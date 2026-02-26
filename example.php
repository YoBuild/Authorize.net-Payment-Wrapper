<?php

declare(strict_types=1);

/**
 * Example usage of the Yohns\Payments wrapper.
 *
 * Install the Authorize.Net SDK first:
 *   composer require authorizenet/authorizenet
 *
 * Then autoload both the SDK and this wrapper via composer.json (see below).
 */

require __DIR__ . '/vendor/autoload.php';

use Yohns\Payments\BillingAddress;
use Yohns\Payments\CreditCard;
use Yohns\Payments\LineItem;
use Yohns\Payments\PaymentGateway;

// -----------------------------------------------------------------------
// 1. Instantiate the gateway (flip sandbox: false for production)
// -----------------------------------------------------------------------
$gateway = new PaymentGateway(
	apiLoginId:     'YOUR_API_LOGIN_ID',
  transactionKey: 'YOUR_TRANSACTION_KEY',
	sandbox:        true,
);

// -----------------------------------------------------------------------
// 2. Build line items from your product/menu catalog
//    LineItem(itemId, name, unitPrice, quantity, description, taxable)
// -----------------------------------------------------------------------
$items = [
	new LineItem('SKU-001', 'Blue Dream 3.5g',   45.00, 2, '3.5g premium flower',    taxable: true),
	new LineItem('SKU-002', 'Gelato Cartridge',  55.00, 1, '1g live resin cart',     taxable: true),
	new LineItem('SKU-003', 'Glass One-Hitter',  12.00, 1, 'Borosilicate hand pipe', taxable: false),
];

// -----------------------------------------------------------------------
// 3. Build billing address
// -----------------------------------------------------------------------
$billing = new BillingAddress(
	firstName: 'Jane',
	lastName:  'Doe',
	address:   '420 High St',
	city:      'Denver',
	state:     'CO',
	zip:       '80202',
	country:   'US',
	phone:     '303-555-0100',
	email:     'jane@example.com',
);

// -----------------------------------------------------------------------
// 4. Build credit card
// -----------------------------------------------------------------------
$card = new CreditCard(
	cardNumber:     '4111111111111111', // Visa test number
	expirationDate: '2027-09',          // YYYY-MM
	cvv:            '123',
);

// -----------------------------------------------------------------------
// 5. Charge — total is auto-calculated from line items + tax + shipping
// -----------------------------------------------------------------------
$result = $gateway->charge(
	card:        $card,
	billing:     $billing,
	lineItems:   $items,
	tax:         9.45,
	shipping:    5.00,
	invoiceNum:  'INV-1042',
	description: 'Dispensary online order',
	customerIp:  $_SERVER['REMOTE_ADDR'] ?? '',
);

// -----------------------------------------------------------------------
// 6. Handle the result
// -----------------------------------------------------------------------
if ($result->isSuccess()) {
	echo "✅ Payment approved!\n";
	echo "   Transaction ID : " . $result->getTransactionId() . "\n";
	echo "   Auth Code      : " . $result->getAuthCode() . "\n";
	echo "   Card           : " . $result->getAccountType() . ' ' . $result->getAccountNumber() . "\n";
	echo "   AVS            : " . $result->getAvsResultCode() . "\n";
	echo "   CVV            : " . $result->getCvvResultCode() . "\n";
} elseif ($result->isDeclined()) {
	echo "❌ Card declined: " . $result->getErrorText() . "\n";
} elseif ($result->isHeld()) {
	echo "⏳ Transaction held for review. ID: " . $result->getTransactionId() . "\n";
} else {
	echo "⚠️  Error [{$result->getErrorCode()}]: " . $result->getErrorText() . "\n";
}

/*
 * -----------------------------------------------------------------------
 * composer.json autoload example
 * -----------------------------------------------------------------------
 *
 * {
 *     "require": {
 *         "authorizenet/authorizenet": "^2.0"
 *     },
 *     "autoload": {
 *         "psr-4": {
 *             "Yohns\\Payments\\": "src/Yohns/Payments/"
 *         }
 *     }
 * }
 *
 * Then run: composer dump-autoload
 * -----------------------------------------------------------------------
 */
