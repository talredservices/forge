---
title: Policies
description: Post-construction behavioral logic for Value Objects.
navigation:
  title: Policies
  order: 5
---

# Policies

Policies execute behavioral logic **after** a Value Object is fully constructed and validated. They receive the completed VO and can enforce business decisions, compute metadata, or apply side effects.

## Policy interface

```php
use Talred\Domain\Interfaces\Policy;

interface Policy
{
    public function decide(mixed $context): mixed;
}
```

## Abstract Policy contract

```php
use Talred\Domain\Contracts\Policy;

abstract class Policy implements PolicyInterface
{
    abstract public function apply(VO $vo, array $options = []): mixed;
    public function and(PolicyInterface $policy): PolicyInterface;
}
```

## Using policies on Value Objects

Attach policies at the class level with `#[UsePolicy]`:

```php
use Talred\Domain\Attributes\UsePolicy;
use Talred\Domain\Policies\EmailPolicy;

#[UsePolicy(EmailPolicy::class, [
    'requireVerified' => false,
    'trustedDomains' => ['company.com'],
])]
class Email extends ValueObject
{
    public function __construct(
        public readonly string $address,
        public readonly ?\DateTimeImmutable $verifiedAt = null,
    ) {}
}
```

Policies run as the **last step** of the resolution pipeline, after all validation.

## `#[UsePolicy]` attribute

```php
#[Attribute(Attribute::TARGET_CLASS, Attribute::IS_REPEATABLE)]
class UsePolicy
{
    public function __construct(
        public string $policyClass,
        public array $options = [],
    ) {}
}
```

Note: Policies are applied at class level, not property level.

## Built-in policies

### EmailPolicy

Post-construction policy for Email Value Objects.

```php
#[UsePolicy(EmailPolicy::class, [
    'requireVerified' => false,
    'trustedDomains' => ['company.com', 'partner.org'],
])]
class Email extends ValueObject { ... }
```

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `requireVerified` | `bool` | `false` | Require email to be verified |
| `trustedDomains` | `string[]` | `[]` | Domains considered trusted |

Returns metadata about domain trust status.

### PasswordPolicy

Handles password hashing and verification.

```php
use Talred\Domain\Policies\PasswordPolicy;

$policy = new PasswordPolicy();

// Hash a password
$hash = $policy->apply('MySecret123!');
// → bcrypt hash string

// Verify
$policy->verify('MySecret123!', $hash); // true
```

The policy uses `PASSWORD_BCRYPT` by default.

### AccessTokenPolicy

Generates and validates access tokens.

```php
use Talred\Domain\Policies\AccessTokenPolicy;

$policy = new AccessTokenPolicy();

// Generate a token with 30-day TTL
$result = $policy->generate(ttlSeconds: 2592000);
// ['token' => 'a1b2c3...', 'expiresAt' => DateTimeImmutable]

// Validate
$policy->isValid($token, $expiresAt); // true/false
```

## Composing policies

Run multiple policies sequentially:

```php
$policy = (new EmailPolicy())->and(new AuditPolicy());

// Both policies execute, in order
$policy->apply($emailVO);
```

## Creating custom policies

```php
<?php

declare(strict_types=1);

namespace App\Domain\Policies;

use Talred\Domain\Contracts\Policy;
use Talred\Domain\Interfaces\VO;

class CreditLimitPolicy extends Policy
{
    public function apply(VO $vo, array $options = []): mixed
    {
        $maxCredit = $options['maxCredit'] ?? 10000.00;
        $amount = $vo->get('amount');

        if ($amount > $maxCredit) {
            throw new \DomainException(
                "Credit amount {$amount} exceeds limit of {$maxCredit}"
            );
        }

        return ['withinLimit' => true, 'remaining' => $maxCredit - $amount];
    }
}
```

Usage:

```php
#[UsePolicy(CreditLimitPolicy::class, ['maxCredit' => 5000.00])]
class Credit extends ValueObject
{
    public function __construct(
        public readonly float $amount,
        public readonly string $currency,
    ) {}
}
```
