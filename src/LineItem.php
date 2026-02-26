<?php

declare(strict_types=1);

namespace Yohns\Payments;

/**
 * Represents a single line item (product) on an order.
 *
 * Authorize.Net supports up to 30 line items per transaction.
 * itemId max 31 chars, name max 31 chars, description max 255 chars.
 */
class LineItem {

	public function __construct(
		private string $itemId,
		private string $name,
		private float $unitPrice,
		private int $quantity = 1,
		private string $description = '',
		private bool $taxable = false,
	) {
		if (strlen($this->itemId) > 31) {
			throw new \InvalidArgumentException('LineItem itemId must be 31 characters or fewer.');
		}
		if (strlen($this->name) > 31) {
			throw new \InvalidArgumentException('LineItem name must be 31 characters or fewer.');
		}
		if (strlen($this->description) > 255) {
			throw new \InvalidArgumentException('LineItem description must be 255 characters or fewer.');
		}
		if ($this->quantity < 1) {
			throw new \InvalidArgumentException('LineItem quantity must be at least 1.');
		}
		if ($this->unitPrice < 0) {
			throw new \InvalidArgumentException('LineItem unitPrice cannot be negative.');
		}
	}

	public function getItemId(): string {
		return $this->itemId;
	}

	public function getName(): string {
		return $this->name;
	}

	public function getUnitPrice(): float {
		return $this->unitPrice;
	}

	public function getQuantity(): int {
		return $this->quantity;
	}

	public function getDescription(): string {
		return $this->description;
	}

	public function isTaxable(): bool {
		return $this->taxable;
	}

	/**
	 * Total price for this line item (unitPrice × quantity).
	 */
	public function getTotal(): float {
		return round($this->unitPrice * $this->quantity, 2);
	}
}
