<?php

declare(strict_types=1);

namespace Yohns\Payments;

/**
 * Billing address for a payment transaction.
 */
class BillingAddress {

	public function __construct(
		private string $firstName,
		private string $lastName,
		private string $address,
		private string $city,
		private string $state,
		private string $zip,
		private string $country = 'US',
		private string $company = '',
		private string $phone = '',
		private string $email = '',
	) {}

	public function getFirstName(): string {
		return $this->firstName;
	}

	public function getLastName(): string {
		return $this->lastName;
	}

	public function getAddress(): string {
		return $this->address;
	}

	public function getCity(): string {
		return $this->city;
	}

	public function getState(): string {
		return $this->state;
	}

	public function getZip(): string {
		return $this->zip;
	}

	public function getCountry(): string {
		return $this->country;
	}

	public function getCompany(): string {
		return $this->company;
	}

	public function getPhone(): string {
		return $this->phone;
	}

	public function getEmail(): string {
		return $this->email;
	}
}
