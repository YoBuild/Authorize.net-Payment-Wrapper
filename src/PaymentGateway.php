<?php

declare(strict_types=1);

namespace Yohns\Payments;

use net\authorize\api\contract\v1 as AnetAPI;
use net\authorize\api\controller as AnetController;
use net\authorize\api\constants\ANetEnvironment;

/**
 * Authorize.Net payment gateway wrapper.
 *
 * Usage:
 *
 *   $gateway = new PaymentGateway(
 *       apiLoginId:     'YOUR_LOGIN_ID',
 *       transactionKey: 'YOUR_TRANSACTION_KEY',
 *       sandbox:        true,
 *   );
 *
 *   $card    = new CreditCard('4111111111111111', '2027-09', '123');
 *   $billing = new BillingAddress('John', 'Doe', '123 Main St', 'Denver', 'CO', '80202');
 *
 *   $items = [
 *       new LineItem('SKU-001', 'Blue Dream 3.5g', 45.00, 2, '3.5g flower', taxable: true),
 *       new LineItem('SKU-002', 'Glass Pipe',       18.00, 1, 'Borosilicate hand pipe'),
 *   ];
 *
 *   $result = $gateway->charge(
 *       card:        $card,
 *       billing:     $billing,
 *       lineItems:   $items,
 *       invoiceNum:  'INV-1042',
 *       description: 'In-store order',
 *   );
 *
 *   if ($result->isSuccess()) {
 *       echo 'Charged! Transaction ID: ' . $result->getTransactionId();
 *   } else {
 *       echo 'Failed: ' . $result->getErrorText();
 *   }
 */
class PaymentGateway {

	private const MAX_LINE_ITEMS = 30;

	public function __construct(
		private string $apiLoginId,
		private string $transactionKey,
		private bool $sandbox = true,
	) {}

	/**
	 * Authorize and capture a credit card charge.
	 *
	 * The total amount is automatically calculated from all LineItem totals
	 * plus optional tax, shipping, and discount amounts.
	 *
	 * @param LineItem[]    $lineItems   Up to 30 line items.
	 * @param float         $tax         Tax amount (not a rate).
	 * @param float         $shipping    Shipping amount.
	 * @param float         $discount    Discount amount (positive number, will be subtracted).
	 * @param string        $invoiceNum  Max 20 chars.
	 * @param string        $description Max 255 chars.
	 * @param string        $customerId  Your internal customer ID (max 20 chars).
	 * @param string        $customerIp  Customer IP for fraud detection.
	 */
	public function charge(
		CreditCard $card,
		BillingAddress $billing,
		array $lineItems,
		float $tax = 0.00,
		float $shipping = 0.00,
		float $discount = 0.00,
		string $invoiceNum = '',
		string $description = '',
		string $customerId = '',
		string $customerIp = '',
	): PaymentResult {
		$this->validateLineItems($lineItems);

		$subtotal = $this->calculateSubtotal($lineItems);
		$total    = round($subtotal + $tax + $shipping - $discount, 2);

		if ($total <= 0) {
			throw new \InvalidArgumentException('Order total must be greater than zero.');
		}

		// --- Merchant authentication ---
		$merchantAuth = new AnetAPI\MerchantAuthenticationType();
		$merchantAuth->setName($this->apiLoginId);
		$merchantAuth->setTransactionKey($this->transactionKey);

		// --- Credit card ---
		$anetCard = new AnetAPI\CreditCardType();
		$anetCard->setCardNumber($card->getCardNumber());
		$anetCard->setExpirationDate($card->getExpirationDate());
		if ($card->getCvv() !== '') {
			$anetCard->setCardCode($card->getCvv());
		}

		$payment = new AnetAPI\PaymentType();
		$payment->setCreditCard($anetCard);

		// --- Billing address ---
		$billTo = new AnetAPI\CustomerAddressType();
		$billTo->setFirstName($billing->getFirstName());
		$billTo->setLastName($billing->getLastName());
		$billTo->setAddress($billing->getAddress());
		$billTo->setCity($billing->getCity());
		$billTo->setState($billing->getState());
		$billTo->setZip($billing->getZip());
		$billTo->setCountry($billing->getCountry());
		if ($billing->getCompany() !== '') {
			$billTo->setCompany($billing->getCompany());
		}
		if ($billing->getPhone() !== '') {
			$billTo->setPhoneNumber($billing->getPhone());
		}
		if ($billing->getEmail() !== '') {
			$billTo->setEmail($billing->getEmail());
		}

		// --- Line items ---
		$anetLineItems = $this->buildLineItems($lineItems);

		// --- Order ---
		$order = new AnetAPI\OrderType();
		if ($invoiceNum !== '') {
			$order->setInvoiceNumber(substr($invoiceNum, 0, 20));
		}
		if ($description !== '') {
			$order->setDescription(substr($description, 0, 255));
		}

		// --- Transaction request ---
		$txnRequest = new AnetAPI\TransactionRequestType();
		$txnRequest->setTransactionType('authCaptureTransaction');
		$txnRequest->setAmount($total);
		$txnRequest->setPayment($payment);
		$txnRequest->setBillTo($billTo);
		$txnRequest->setOrder($order);
		$txnRequest->setLineItems($anetLineItems);

		// --- Optional extended amounts ---
		if ($tax > 0) {
			$taxItem = new AnetAPI\ExtendedAmountType();
			$taxItem->setAmount(round($tax, 2));
			$taxItem->setName('Tax');
			$txnRequest->setTax($taxItem);
		}
		if ($shipping > 0) {
			$shipItem = new AnetAPI\ExtendedAmountType();
			$shipItem->setAmount(round($shipping, 2));
			$shipItem->setName('Shipping');
			$txnRequest->setShipping($shipItem);
		}

		// --- Customer data ---
		if ($customerId !== '' || $billing->getEmail() !== '') {
			$customer = new AnetAPI\CustomerDataType();
			$customer->setType('individual');
			if ($customerId !== '') {
				$customer->setId(substr($customerId, 0, 20));
			}
			if ($billing->getEmail() !== '') {
				$customer->setEmail($billing->getEmail());
			}
			$txnRequest->setCustomer($customer);
		}

		// --- Fraud detection: customer IP ---
		if ($customerIp !== '') {
			$txnRequest->setCustomerIP($customerIp);
		}

		// --- Top-level request ---
		$request = new AnetAPI\CreateTransactionRequest();
		$request->setMerchantAuthentication($merchantAuth);
		$request->setRefId('ref' . time());
		$request->setTransactionRequest($txnRequest);

		// --- Execute ---
		$controller = new AnetController\CreateTransactionController($request);
		$endpoint   = $this->sandbox ? ANetEnvironment::SANDBOX : ANetEnvironment::PRODUCTION;
		$response   = $controller->executeWithApiResponse($endpoint);

		return $this->parseResponse($response);
	}

	/**
	 * Authorize only (no capture). Use captureTransaction() later with the returned transactionId.
	 */
	public function authorizeOnly(
		CreditCard $card,
		BillingAddress $billing,
		array $lineItems,
		float $tax = 0.00,
		float $shipping = 0.00,
		float $discount = 0.00,
		string $invoiceNum = '',
		string $description = '',
	): PaymentResult {
		// Reuse charge() internals but swap transaction type.
		// We override via a thin internal flag for DRY code.
		return $this->executeTransaction(
			type:        'authOnlyTransaction',
			card:        $card,
			billing:     $billing,
			lineItems:   $lineItems,
			tax:         $tax,
			shipping:    $shipping,
			discount:    $discount,
			invoiceNum:  $invoiceNum,
			description: $description,
		);
	}

	/**
	 * Capture a previously authorized transaction.
	 *
	 * @param string $priorTransactionId  The transactionId from a prior authorizeOnly() call.
	 */
	public function captureAuthorized(string $priorTransactionId, float $amount): PaymentResult {
		$merchantAuth = new AnetAPI\MerchantAuthenticationType();
		$merchantAuth->setName($this->apiLoginId);
		$merchantAuth->setTransactionKey($this->transactionKey);

		$txnRequest = new AnetAPI\TransactionRequestType();
		$txnRequest->setTransactionType('priorAuthCaptureTransaction');
		$txnRequest->setAmount(round($amount, 2));
		$txnRequest->setRefTransId($priorTransactionId);

		$request = new AnetAPI\CreateTransactionRequest();
		$request->setMerchantAuthentication($merchantAuth);
		$request->setRefId('ref' . time());
		$request->setTransactionRequest($txnRequest);

		$controller = new AnetController\CreateTransactionController($request);
		$endpoint   = $this->sandbox ? ANetEnvironment::SANDBOX : ANetEnvironment::PRODUCTION;
		$response   = $controller->executeWithApiResponse($endpoint);

		return $this->parseResponse($response);
	}

	/**
	 * Void an unsettled transaction.
	 */
	public function void(string $transactionId): PaymentResult {
		$merchantAuth = new AnetAPI\MerchantAuthenticationType();
		$merchantAuth->setName($this->apiLoginId);
		$merchantAuth->setTransactionKey($this->transactionKey);

		$txnRequest = new AnetAPI\TransactionRequestType();
		$txnRequest->setTransactionType('voidTransaction');
		$txnRequest->setRefTransId($transactionId);

		$request = new AnetAPI\CreateTransactionRequest();
		$request->setMerchantAuthentication($merchantAuth);
		$request->setRefId('ref' . time());
		$request->setTransactionRequest($txnRequest);

		$controller = new AnetController\CreateTransactionController($request);
		$endpoint   = $this->sandbox ? ANetEnvironment::SANDBOX : ANetEnvironment::PRODUCTION;
		$response   = $controller->executeWithApiResponse($endpoint);

		return $this->parseResponse($response);
	}

	/**
	 * Refund a settled transaction.
	 *
	 * @param string $transactionId   Original transaction ID.
	 * @param string $maskedCardNum   Last 4 digits of card (e.g. "1111").
	 * @param string $expirationDate  In YYYY-MM format.
	 * @param float  $amount          Amount to refund (can be partial).
	 */
	public function refund(
		string $transactionId,
		string $maskedCardNum,
		string $expirationDate,
		float $amount,
	): PaymentResult {
		$merchantAuth = new AnetAPI\MerchantAuthenticationType();
		$merchantAuth->setName($this->apiLoginId);
		$merchantAuth->setTransactionKey($this->transactionKey);

		$creditCard = new AnetAPI\CreditCardType();
		$creditCard->setCardNumber($maskedCardNum);
		$creditCard->setExpirationDate($expirationDate);

		$payment = new AnetAPI\PaymentType();
		$payment->setCreditCard($creditCard);

		$txnRequest = new AnetAPI\TransactionRequestType();
		$txnRequest->setTransactionType('refundTransaction');
		$txnRequest->setAmount(round($amount, 2));
		$txnRequest->setPayment($payment);
		$txnRequest->setRefTransId($transactionId);

		$request = new AnetAPI\CreateTransactionRequest();
		$request->setMerchantAuthentication($merchantAuth);
		$request->setRefId('ref' . time());
		$request->setTransactionRequest($txnRequest);

		$controller = new AnetController\CreateTransactionController($request);
		$endpoint   = $this->sandbox ? ANetEnvironment::SANDBOX : ANetEnvironment::PRODUCTION;
		$response   = $controller->executeWithApiResponse($endpoint);

		return $this->parseResponse($response);
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Internal shared transaction builder used by charge() and authorizeOnly().
	 *
	 * @param LineItem[] $lineItems
	 */
	private function executeTransaction(
		string $type,
		CreditCard $card,
		BillingAddress $billing,
		array $lineItems,
		float $tax,
		float $shipping,
		float $discount,
		string $invoiceNum,
		string $description,
	): PaymentResult {
		$this->validateLineItems($lineItems);

		$subtotal = $this->calculateSubtotal($lineItems);
		$total    = round($subtotal + $tax + $shipping - $discount, 2);

		if ($total <= 0) {
			throw new \InvalidArgumentException('Order total must be greater than zero.');
		}

		$merchantAuth = new AnetAPI\MerchantAuthenticationType();
		$merchantAuth->setName($this->apiLoginId);
		$merchantAuth->setTransactionKey($this->transactionKey);

		$anetCard = new AnetAPI\CreditCardType();
		$anetCard->setCardNumber($card->getCardNumber());
		$anetCard->setExpirationDate($card->getExpirationDate());
		if ($card->getCvv() !== '') {
			$anetCard->setCardCode($card->getCvv());
		}

		$payment = new AnetAPI\PaymentType();
		$payment->setCreditCard($anetCard);

		$billTo = new AnetAPI\CustomerAddressType();
		$billTo->setFirstName($billing->getFirstName());
		$billTo->setLastName($billing->getLastName());
		$billTo->setAddress($billing->getAddress());
		$billTo->setCity($billing->getCity());
		$billTo->setState($billing->getState());
		$billTo->setZip($billing->getZip());
		$billTo->setCountry($billing->getCountry());
		if ($billing->getCompany() !== '') {
			$billTo->setCompany($billing->getCompany());
		}
		if ($billing->getPhone() !== '') {
			$billTo->setPhoneNumber($billing->getPhone());
		}
		if ($billing->getEmail() !== '') {
			$billTo->setEmail($billing->getEmail());
		}

		$order = new AnetAPI\OrderType();
		if ($invoiceNum !== '') {
			$order->setInvoiceNumber(substr($invoiceNum, 0, 20));
		}
		if ($description !== '') {
			$order->setDescription(substr($description, 0, 255));
		}

		$txnRequest = new AnetAPI\TransactionRequestType();
		$txnRequest->setTransactionType($type);
		$txnRequest->setAmount($total);
		$txnRequest->setPayment($payment);
		$txnRequest->setBillTo($billTo);
		$txnRequest->setOrder($order);
		$txnRequest->setLineItems($this->buildLineItems($lineItems));

		if ($tax > 0) {
			$taxItem = new AnetAPI\ExtendedAmountType();
			$taxItem->setAmount(round($tax, 2));
			$taxItem->setName('Tax');
			$txnRequest->setTax($taxItem);
		}
		if ($shipping > 0) {
			$shipItem = new AnetAPI\ExtendedAmountType();
			$shipItem->setAmount(round($shipping, 2));
			$shipItem->setName('Shipping');
			$txnRequest->setShipping($shipItem);
		}

		$request = new AnetAPI\CreateTransactionRequest();
		$request->setMerchantAuthentication($merchantAuth);
		$request->setRefId('ref' . time());
		$request->setTransactionRequest($txnRequest);

		$controller = new AnetController\CreateTransactionController($request);
		$endpoint   = $this->sandbox ? ANetEnvironment::SANDBOX : ANetEnvironment::PRODUCTION;
		$response   = $controller->executeWithApiResponse($endpoint);

		return $this->parseResponse($response);
	}

	/**
	 * Convert our LineItem objects into Authorize.Net LineItemType objects.
	 *
	 * @param  LineItem[]                    $lineItems
	 * @return AnetAPI\LineItemType[]
	 */
	private function buildLineItems(array $lineItems): array {
		$anetItems = [];

		foreach ($lineItems as $item) {
			$anetItem = new AnetAPI\LineItemType();
			$anetItem->setItemId($item->getItemId());
			$anetItem->setName($item->getName());
			$anetItem->setQuantity($item->getQuantity());
			$anetItem->setUnitPrice($item->getUnitPrice());
			$anetItem->setTaxable($item->isTaxable());

			if ($item->getDescription() !== '') {
				$anetItem->setDescription($item->getDescription());
			}

			$anetItems[] = $anetItem;
		}

		return $anetItems;
	}

	/**
	 * Sum all LineItem totals.
	 *
	 * @param LineItem[] $lineItems
	 */
	private function calculateSubtotal(array $lineItems): float {
		return array_reduce(
			$lineItems,
			fn(float $carry, LineItem $item): float => $carry + $item->getTotal(),
			0.00,
		);
	}

	/**
	 * @param LineItem[] $lineItems
	 */
	private function validateLineItems(array $lineItems): void {
		if (count($lineItems) === 0) {
			throw new \InvalidArgumentException('At least one LineItem is required.');
		}
		if (count($lineItems) > self::MAX_LINE_ITEMS) {
			throw new \InvalidArgumentException(
				sprintf('Authorize.Net supports a maximum of %d line items per transaction.', self::MAX_LINE_ITEMS)
			);
		}
		foreach ($lineItems as $item) {
			if (!$item instanceof LineItem) {
				throw new \InvalidArgumentException('All items in $lineItems must be instances of LineItem.');
			}
		}
	}

	/**
	 * Parse the Authorize.Net API response into a PaymentResult.
	 */
	private function parseResponse(mixed $response): PaymentResult {
		if ($response === null) {
			return new PaymentResult(
				success:      false,
				transactionId: '',
				authCode:     '',
				responseCode: 3,
				responseText: 'No response received from gateway.',
				errorCode:    'NO_RESPONSE',
				errorText:    'The gateway returned no response. Check network connectivity.',
			);
		}

		$tresponse = $response->getTransactionResponse();

		if ($response->getMessages()->getResultCode() === 'Ok'
			&& $tresponse !== null
			&& $tresponse->getMessages() !== null
		) {
			return new PaymentResult(
				success:       true,
				transactionId: (string) $tresponse->getTransId(),
				authCode:      (string) $tresponse->getAuthCode(),
				responseCode:  (int) $tresponse->getResponseCode(),
				responseText:  $tresponse->getMessages()[0]->getDescription(),
				avsResultCode: (string) $tresponse->getAvsResultCode(),
				cvvResultCode: (string) $tresponse->getCvvResultCode(),
				accountNumber: (string) $tresponse->getAccountNumber(),
				accountType:   (string) $tresponse->getAccountType(),
			);
		}

		// Transaction-level error (declined, CVV mismatch, etc.)
		if ($tresponse !== null && $tresponse->getErrors() !== null) {
			$error = $tresponse->getErrors()[0];
			return new PaymentResult(
				success:       false,
				transactionId: (string) $tresponse->getTransId(),
				authCode:      '',
				responseCode:  (int) $tresponse->getResponseCode(),
				responseText:  $error->getErrorText(),
				avsResultCode: (string) $tresponse->getAvsResultCode(),
				cvvResultCode: (string) $tresponse->getCvvResultCode(),
				accountNumber: (string) $tresponse->getAccountNumber(),
				accountType:   (string) $tresponse->getAccountType(),
				errorCode:     $error->getErrorCode(),
				errorText:     $error->getErrorText(),
			);
		}

		// API-level error (bad credentials, malformed request, etc.)
		$messages = $response->getMessages()->getMessage();
		$message  = $messages[0] ?? null;

		return new PaymentResult(
			success:       false,
			transactionId: '',
			authCode:      '',
			responseCode:  3,
			responseText:  $message?->getText() ?? 'Unknown API error.',
			errorCode:     $message?->getCode() ?? 'UNKNOWN',
			errorText:     $message?->getText() ?? 'Unknown API error.',
		);
	}
}
