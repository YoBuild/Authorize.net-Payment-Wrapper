<?php

declare(strict_types=1);

namespace Yohns\Payments;

/**
 * Credit card data for a payment transaction.
 *
 * expirationDate must be in YYYY-MM format as required by Authorize.Net.
 */
class CreditCard {

	public function __construct(
		private string $cardNumber,
		private string $expirationDate,
		private string $cvv = '',
	) {
		// Basic card number sanity — strip spaces/dashes, digits only
		$stripped = preg_replace('/\D/', '', $this->cardNumber);
		if (strlen($stripped) < 13 || strlen($stripped) > 19) {
			throw new \InvalidArgumentException('Invalid card number length.');
		}
		$this->cardNumber = $stripped;

		// Enforce YYYY-MM format
		if (!preg_match('/^\d{4}-\d{2}$/', $this->expirationDate)) {
			throw new \InvalidArgumentException('expirationDate must be in YYYY-MM format (e.g. 2027-09).');
		}
	}

	public function getCardNumber(): string {
		return $this->cardNumber;
	}

	public function getExpirationDate(): string {
		return $this->expirationDate;
	}

	public function getCvv(): string {
		return $this->cvv;
	}
}
