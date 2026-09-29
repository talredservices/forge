---
title: Invariants
description: Class-level structural guarantees enforced after Value Object construction.
navigation:
  title: Invariants
  order: 6
---

# Invariants

Invariants enforce structural guarantees across **multiple properties** of a Value Object. While Rules validate individual fields, Invariants validate the object as a whole — ensuring that the combination of properties is consistent and valid.

## Invariant interface

```php
use Talred\Domain\Interfaces\Invariant;

interface Invariant
{
    public function ensure(mixed $subject): void;
}
```

## Abstract Invariant contract

```php
use Talred\Domain\Contracts\Invariant;

abstract class Invariant implements InvariantInterface
{
    abstract public function ensure(VO $vo, array $options = []): void;

    protected function assertVOType(VO $vo, string $expectedClass): void;
    protected function throwIf(bool $condition, string $message): void;

    public function and(InvariantInterface $invariant): InvariantInterface;
    public function or(InvariantInterface $invariant): InvariantInterface;
}
```

### Helper methods

| Method | Description |
|--------|-------------|
| `assertVOType($vo, $class)` | Assert the VO is of the expected class |
| `throwIf($condition, $message)` | Throw `DomainException` if condition is true |

## Using invariants on Value Objects

Attach invariants at the class level with `#[UseInvariant]`:

```php
use Talred\Domain\Attributes\UseInvariant;

#[UseInvariant(CreditInvariant::class)]
class Credit extends ValueObject
{
    public function __construct(
        public readonly float $amount,
        public readonly string $currency,
    ) {}
}
```

Invariants execute after all per-property validation (Rules, Specs) but before Policies.

## `#[UseInvariant]` attribute

```php
#[Attribute(Attribute::TARGET_CLASS, Attribute::IS_REPEATABLE)]
class UseInvariant
{
    public function __construct(
        public string $invariantClass,
        public array $options = [],
    ) {}
}
```

## Built-in invariants

### CreditInvariant

Ensures credit structure validity — amount is non-negative and currency is provided.

```php
#[UseInvariant(CreditInvariant::class)]
class Credit extends ValueObject
{
    public function __construct(
        public readonly float $amount,
        public readonly string $currency,
    ) {}
}
```

Validates:
- `amount` is non-negative
- `currency` is a non-empty, uppercase string

Throws: `DomainException` on violation.

### EmailVOInvariant

Ensures Email VO structural constraints.

```php
#[UseInvariant(EmailVOInvariant::class, [
    'allowFutureVerified' => false,
    'domainRequiresVerified' => ['secure.com'],
])]
class Email extends ValueObject { ... }
```

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `allowFutureVerified` | `bool` | `false` | Allow `verifiedAt` in the future |
| `domainRequiresVerified` | `string[]` | `[]` | Domains that require verified emails |

Validates:
- `verifiedAt` is not in the future (unless allowed)
- If domain is in `domainRequiresVerified`, the email must be verified

## Composing invariants

### AND composition

Both invariants must hold:

```php
$invariant = (new CreditInvariant())->and(new BalanceLimitInvariant());
$invariant->ensure($creditVO, ['maxBalance' => 50000]);
```

### OR composition

At least one invariant must hold:

```php
$invariant = (new StandardPricingInvariant())->or(new PromotionalPricingInvariant());
$invariant->ensure($priceVO);
```

## Creating custom invariants

```php
<?php

declare(strict_types=1);

namespace App\Domain\Invariants;

use Talred\Domain\Contracts\Invariant;
use Talred\Domain\Interfaces\VO;

class DateRangeInvariant extends Invariant
{
    public function ensure(VO $vo, array $options = []): void
    {
        $startDate = $vo->get('startDate');
        $endDate = $vo->get('endDate');

        $this->throwIf(
            $endDate <= $startDate,
            'End date must be after start date'
        );

        $maxDays = $options['maxDays'] ?? null;
        if ($maxDays !== null) {
            $diff = $startDate->diff($endDate)->days;
            $this->throwIf(
                $diff > $maxDays,
                "Date range must not exceed {$maxDays} days"
            );
        }
    }
}
```

Usage:

```php
#[UseInvariant(DateRangeInvariant::class, ['maxDays' => 365])]
class BookingPeriod extends ValueObject
{
    public function __construct(
        public readonly \DateTimeImmutable $startDate,
        public readonly \DateTimeImmutable $endDate,
    ) {}
}
```
