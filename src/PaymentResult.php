<?php

declare(strict_types=1);

namespace Yohns\Payments;

/**
 * Immutable result object returned by PaymentGateway after a charge attempt.
 *
 * responseCode values:
 *   1 = Approved
 *   2 = Declined
 *   3 = Error
 *   4 = Held for Review
 */
class PaymentResult {

	public function __construct(
		private bool $success,
		private string $transactionId,
		private string $authCode,
		private int $responseCode,
		private string $responseText,
		private string $avsResultCode = '',
		private string $cvvResultCode = '',
		private string $accountNumber = '',
		private string $accountType = '',
		private ?string $errorCode = null,
		private ?string $errorText = null,
	) {}

	public function isSuccess(): bool {
		return $this->success;
	}

	public function getTransactionId(): string {
		return $this->transactionId;
	}

	public function getAuthCode(): string {
		return $this->authCode;
	}

	/**
	 * 1 = Approved, 2 = Declined, 3 = Error, 4 = Held for Review.
	 */
	public function getResponseCode(): int {
		return $this->responseCode;
	}

	public function getResponseText(): string {
		return $this->responseText;
	}

	public function getAvsResultCode(): string {
		return $this->avsResultCode;
	}

	public function getCvvResultCode(): string {
		return $this->cvvResultCode;
	}

	/** Masked card number returned by Authorize.Net (e.g. XXXX1111). */
	public function getAccountNumber(): string {
		return $this->accountNumber;
	}

	public function getAccountType(): string {
		return $this->accountType;
	}

	public function getErrorCode(): ?string {
		return $this->errorCode;
	}

	public function getErrorText(): ?string {
		return $this->errorText;
	}

	/**
	 * Convenience: was the card declined specifically?
	 */
	public function isDeclined(): bool {
		return $this->responseCode === 2;
	}

	/**
	 * Convenience: was the transaction held for review?
	 */
	public function isHeld(): bool {
		return $this->responseCode === 4;
	}

	public function toArray(): array {
		return [
			'success'        => $this->success,
			'transactionId'  => $this->transactionId,
			'authCode'       => $this->authCode,
			'responseCode'   => $this->responseCode,
			'responseText'   => $this->responseText,
			'avsResultCode'  => $this->avsResultCode,
			'cvvResultCode'  => $this->cvvResultCode,
			'accountNumber'  => $this->accountNumber,
			'accountType'    => $this->accountType,
			'errorCode'      => $this->errorCode,
			'errorText'      => $this->errorText,
		];
	}
}
