# Yohns\Payments

A PHP 8.2+ wrapper around the [Authorize.Net PHP SDK](https://github.com/AuthorizeNet/sdk-php) with first-class line item support. Designed to make it easy to submit orders with product/menu catalog data attached to each transaction.

---

## Requirements

- PHP 8.2 or newer
- `ext-curl`, `ext-json`, `ext-xml`
- [authorizenet/authorizenet](https://packagist.org/packages/authorizenet/authorizenet) `^2.0`

---

## Installation

```bash
composer require yohns/payments
```

Or clone and install dependencies manually:

```bash
git clone https://github.com/yourname/payments.git
cd payments
composer install
```

---

## Directory Structure

```
src/
    BillingAddress.php
    CreditCard.php
    LineItem.php
    PaymentGateway.php
    PaymentResult.php
composer.json
README.md
example.php
```

---

## Credentials

Sandbox credentials are obtained from [developer.authorize.net](https://developer.authorize.net).
Production credentials are obtained from [account.authorize.net](https://account.authorize.net).

Both are found under **Account → Security Settings → API Credentials & Keys**.

> The Transaction Key is always exactly **16 characters**. Generating a new one immediately invalidates the previous one.

---

## Quick Start

```php
<?php

use Yohns\Payments\BillingAddress;
use Yohns\Payments\CreditCard;
use Yohns\Payments\LineItem;
use Yohns\Payments\PaymentGateway;

$gateway = new PaymentGateway(
    apiLoginId:     'YOUR_API_LOGIN_ID',
    transactionKey: 'YOUR_TRANSACTION_KEY',
    sandbox:        true, // set false for production
);

$items = [
    new LineItem('SKU-001', 'Blue Dream 3.5g',  45.00, 2, '3.5g premium flower', taxable: true),
    new LineItem('SKU-002', 'Gelato Cartridge', 55.00, 1, '1g live resin cart',  taxable: true),
    new LineItem('SKU-003', 'Glass One-Hitter', 12.00, 1, 'Borosilicate pipe',   taxable: false),
];

$billing = new BillingAddress(
    firstName: 'Jane',
    lastName:  'Doe',
    address:   '420 High St',
    city:      'Denver',
    state:     'CO',
    zip:       '80202',
);

$card = new CreditCard(
    cardNumber:     '4111111111111111',
    expirationDate: '2027-09', // YYYY-MM
    cvv:            '123',
);

$result = $gateway->charge(
    card:        $card,
    billing:     $billing,
    lineItems:   $items,
    tax:         9.45,
    shipping:    5.00,
    invoiceNum:  'INV-1042',
    description: 'Online order',
);

if ($result->isSuccess()) {
    echo 'Approved! Transaction ID: ' . $result->getTransactionId();
} else {
    echo 'Failed: ' . $result->getErrorText();
}
```

---

## Classes

### `PaymentGateway`

The main entry point. Instantiate once and reuse across requests.

```php
$gateway = new PaymentGateway(
    apiLoginId:     string,
    transactionKey: string,
    sandbox:        bool = true,
);
```

#### `charge()`

Authorize and capture in a single step. **The total amount is automatically calculated** from line item totals plus tax and shipping, minus any discount.

```php
$result = $gateway->charge(
    card:        CreditCard,
    billing:     BillingAddress,
    lineItems:   LineItem[],   // 1–30 items required
    tax:         float = 0.00,
    shipping:    float = 0.00,
    discount:    float = 0.00,
    invoiceNum:  string = '',  // max 20 chars
    description: string = '',  // max 255 chars
    customerId:  string = '',  // your internal customer ID, max 20 chars
    customerIp:  string = '',  // enables fraud detection filters
): PaymentResult
```

#### `authorizeOnly()`

Places a hold on the card without capturing funds. Use `captureAuthorized()` later to complete the charge.

```php
$result = $gateway->authorizeOnly(
    card:        CreditCard,
    billing:     BillingAddress,
    lineItems:   LineItem[],
    tax:         float = 0.00,
    shipping:    float = 0.00,
    discount:    float = 0.00,
    invoiceNum:  string = '',
    description: string = '',
): PaymentResult
```

#### `captureAuthorized()`

Captures a previously authorized transaction.

```php
$result = $gateway->captureAuthorized(
    priorTransactionId: string,
    amount:             float,
): PaymentResult
```

#### `void()`

Voids an unsettled transaction.

```php
$result = $gateway->void(transactionId: string): PaymentResult
```

#### `refund()`

Refunds a settled transaction. Supports partial refunds.

```php
$result = $gateway->refund(
    transactionId:   string, // original transaction ID
    maskedCardNum:   string, // last 4 digits of card, e.g. "1111"
    expirationDate:  string, // YYYY-MM
    amount:          float,  // can be less than original for partial refund
): PaymentResult
```

---

### `LineItem`

Represents a single product or menu item on an order. Authorize.Net supports up to **30 line items** per transaction.

```php
$item = new LineItem(
    itemId:      string, // max 31 chars — your SKU or product ID
    name:        string, // max 31 chars — display name
    unitPrice:   float,
    quantity:    int    = 1,
    description: string = '', // max 255 chars
    taxable:     bool   = false,
);

$item->getTotal(); // unitPrice × quantity
```

---

### `CreditCard`

```php
$card = new CreditCard(
    cardNumber:     string, // spaces and dashes are stripped automatically
    expirationDate: string, // must be YYYY-MM format
    cvv:            string = '',
);
```

> Authorize.Net sandbox test card numbers: `4111111111111111` (Visa), `5424000000000015` (Mastercard), `370000000000002` (Amex). Any future expiration date and any CVV work in sandbox.

---

### `BillingAddress`

```php
$billing = new BillingAddress(
    firstName: string,
    lastName:  string,
    address:   string,
    city:      string,
    state:     string,
    zip:       string,
    country:   string = 'US',
    company:   string = '',
    phone:     string = '',
    email:     string = '',
);
```

> Providing address and zip enables AVS (Address Verification System) checks, which reduce fraud risk and can lower interchange fees.

---

### `PaymentResult`

Immutable result object returned by every gateway method.

```php
$result->isSuccess(): bool
$result->isDeclined(): bool    // responseCode === 2
$result->isHeld(): bool        // responseCode === 4 (held for review)

$result->getTransactionId(): string
$result->getAuthCode(): string
$result->getResponseCode(): int    // 1=Approved, 2=Declined, 3=Error, 4=Held
$result->getResponseText(): string
$result->getAvsResultCode(): string
$result->getCvvResultCode(): string
$result->getAccountNumber(): string  // masked, e.g. XXXX1111
$result->getAccountType(): string    // e.g. Visa, Mastercard
$result->getErrorCode(): ?string
$result->getErrorText(): ?string

$result->toArray(): array
```

**AVS result codes:** `Y` = full match, `A` = address only, `Z` = zip only, `N` = no match, `U` = unavailable.

**CVV result codes:** `M` = match, `N` = no match, `P` = not processed, `S` = should be present, `U` = issuer unable to process.

---

## Error Reference

| Code | Meaning |
|---|---|
| `E00007` | Authentication failed — wrong API Login ID or Transaction Key, or sandbox/production mismatch |
| `E00003` | Invalid element — often a malformed request field |
| `E00027` | Transaction was unsuccessful |
| `NO_RESPONSE` | No response from gateway — network or TLS issue |

---

## Sandbox vs Production

```php
// Sandbox (testing)
$gateway = new PaymentGateway('login', 'key', sandbox: true);

// Production (live charges)
$gateway = new PaymentGateway('login', 'key', sandbox: false);
```

Sandbox and production credentials are **not interchangeable**. Using sandbox credentials against production (or vice versa) will return `E00007`.

---

## License

MIT