---
title: Value Objects
description: Immutable domain primitives with automatic resolution pipeline, validation, transformation, and serialization.
navigation:
  title: Value Objects
  order: 1
---

# Value Objects

Value Objects are the fundamental building blocks in Talred Forge. They represent domain concepts as immutable, self-validating objects that are compared by value rather than identity.

## Base class

All Value Objects extend `ValueObject`:

```php
use Talred\Domain\ValueObjects\ValueObject;
```

### Key methods

| Method | Return | Description |
|--------|--------|-------------|
| `resolve(array $data, ?VOConstructionContext $context)` | `static` | Factory — resolves through the full pipeline |
| `get(string $key)` | `mixed` | Access a property by name |
| `toArray()` | `array` | Serialize all properties |
| `equals(VO $other)` | `bool` | Value-based equality comparison |
| `jsonSerialize()` | `array` | JSON serialization support |
| `__toString()` | `string` | String representation |

## Creating a Value Object

### Simple scalar VO

```php
<?php

declare(strict_types=1);

namespace App\Domain\ValueObjects;

use Talred\Domain\ValueObjects\ValueObject;
use Talred\Domain\Attributes\UseRule;
use Talred\Domain\Rules\NonEmptyRule;
use Talred\Domain\Rules\MaxLengthRule;

class ProductName extends ValueObject
{
    public function __construct(
        #[UseRule(NonEmptyRule::class)]
        #[UseRule(MaxLengthRule::class, ['max' => 200])]
        public readonly string $value,
    ) {}
}
```

### Multi-property VO

```php
class Money extends ValueObject
{
    public function __construct(
        #[UseRule(PositiveNumberRule::class)]
        public readonly float $amount,

        #[UseRule(NonEmptyRule::class)]
        public readonly string $currency,
    ) {}

    public function add(Money $other): self
    {
        if ($this->currency !== $other->currency) {
            throw new \DomainException('Cannot add different currencies');
        }
        return self::resolve([
            'amount' => $this->amount + $other->amount,
            'currency' => $this->currency,
        ]);
    }
}
```

### Enum Value Object

PHP enums can implement the `VO` interface:

```php
use Talred\Domain\Interfaces\VO;
use Talred\Domain\ValueObjects\VOConstructionContext;

enum OAuthProvider: string implements VO
{
    case Google = 'google';
    case Microsoft = 'microsoft';
    case Github = 'github';

    public static function resolve(array $data, ?VOConstructionContext $context = null): static
    {
        $value = $data['value'] ?? $data[0] ?? null;
        return self::from($value);
    }

    public function toArray(): array
    {
        return ['value' => $this->value];
    }

    public function equals(VO $vo): bool
    {
        return $vo instanceof self && $vo->value === $this->value;
    }

    public function get(): string
    {
        return $this->value;
    }
}
```

## Resolution

### `resolve()` — the primary factory

Always use `resolve()` to create Value Objects. It runs the full pipeline:

```php
// Resolve from associative array
$email = Email::resolve([
    'address' => '  JOHN@EXAMPLE.COM  ',
    'verifiedAt' => '2024-01-15 10:30:00',
]);

// Pipeline executed:
// 1. Transform: EmailNormalizer trims + lowercases → 'john@example.com'
// 2. Rule: NonEmptyRule passes
// 3. Rule: MaxLengthRule passes
// 4. Spec: EmailFormatSpecification passes
// 5. Nested VO: verifiedAt → DateTimeImmutable
// 6. Invariant: EmailVOInvariant checks structure
// 7. Policy: EmailPolicy runs post-construction logic
```

### Direct construction

You can bypass the pipeline with `new`:

```php
// Direct — NO validation, NO transformation
$email = new Email(address: 'john@example.com');
```

Use direct construction only when restoring from persistence (data is already validated).

### `VOConstructionContext`

Pass runtime options to control the resolution:

```php
use Talred\Domain\ValueObjects\VOConstructionContext;

$context = new VOConstructionContext(
    runtimePreprocessors: [
        'address' => fn(string $v) => strtolower($v),
    ],
    runtimeOptions: [
        'rules' => ['max' => 500],
        'specifications' => ['allowed' => ['example.com']],
    ],
    skipResolve: false,
);

$email = Email::resolve(['address' => 'TEST@EXAMPLE.COM'], $context);
```

| Property | Type | Description |
|----------|------|-------------|
| `runtimePreprocessors` | `array<string, callable>` | Override transformations per property |
| `runtimeOptions` | `array` | Options passed to rules, specs, invariants |
| `skipResolve` | `bool` | Skip the entire pipeline (use raw data) |

## Built-in UUID identifiers

Extend `AbstractUuid` for typed identifier Value Objects:

```php
use Talred\Domain\ValueObjects\AbstractUuid;

class OrderId extends AbstractUuid {}
class UserId extends AbstractUuid {}
class RoleId extends AbstractUuid {}
```

### AbstractUuid API

```php
// Auto-generate UUID v4
$id = new OrderId();

// From existing string
$id = OrderId::fromString('550e8400-e29b-41d4-a716-446655440000');

// Generate a default
$id = OrderId::default();

// Access
$id->get('value');  // "550e8400-..."
$id->toString();    // "550e8400-..."
$id->toArray();     // ['value' => '550e8400-...']
```

## Built-in Value Objects

Talred Forge ships with production-ready VOs:

| Value Object | Properties | Rules / Specs |
|-------------|-----------|---------------|
| `Email` | `address`, `verifiedAt` | EmailNormalizer, NonEmpty, MaxLength, EmailFormat |
| `Username` | `username` | NonEmpty, MaxLength(100) |
| `Password` | `hash` | NonEmpty, PasswordPolicy |
| `RoleName` | `value` | NonEmpty, MaxLength(50) |
| `PermissionName` | `value` | NonEmpty, MaxLength(100) |
| `Description` | `value` | MaxLength(100) |
| `AvatarUrl` | `url` | MaxLength(2048), URL validation |
| `Credit` | `amount`, `currency` | PositiveNumber, CreditInvariant |
| `AccessToken` | `token`, `expiresAt` | Token generation, expiration check |
| `RefreshToken` | `token`, `expiresAt` | Token validation |
| `VerificationCode` | `code` | 6-digit regex pattern |
| `Pagination` | `items`, `total`, `perPage`, `currentPage`, `lastPage` | Structural |
| `UserCredential` | `email`, `password` | Composite VO |

### Password VO

```php
use Talred\Domain\ValueObjects\Password;

// Hash a plain password
$password = Password::resolve(['hash' => 'MySecret123!']);
// → Validates strength, then stores the raw value for hashing

// Restore from an already-hashed value
$password = Password::fromHashed($hashedString);
```

### AccessToken VO

```php
use Talred\Domain\ValueObjects\AccessToken;

// Generate a new token with 30-day TTL
$token = AccessToken::generate(ttlSeconds: 2592000);
$token->get('token');     // random hex string
$token->isExpired();      // false
```

### VerificationCode VO

```php
use Talred\Domain\ValueObjects\VerificationCode;

$code = VerificationCode::generate(); // Random 6-digit code
$code->get('code'); // e.g. "482910"
```

## Accessing properties

### `get()` method

```php
$email = Email::resolve(['address' => 'john@example.com']);

$email->get('address');     // 'john@example.com'
$email->get('verifiedAt');  // null
```

### Array-style with `toArray()`

```php
$data = $email->toArray();
// ['address' => 'john@example.com', 'verifiedAt' => null]
```

### Custom getters via `$getters`

Override the `$getters` property to expose custom accessor names:

```php
class Email extends ValueObject
{
    protected array $getters = ['address', 'verifiedAt', 'domain'];

    public function getDomain(): string
    {
        return explode('@', $this->address)[1];
    }
}

$email->get('domain'); // 'example.com'
```

## Equality

Value Objects are compared by value, not identity:

```php
$a = Email::resolve(['address' => 'john@example.com']);
$b = Email::resolve(['address' => 'john@example.com']);
$c = Email::resolve(['address' => 'jane@example.com']);

$a->equals($b); // true  — same value
$a->equals($c); // false — different value
$a === $b;       // false — different instances
```

## Serialization

```php
$email = Email::resolve(['address' => 'john@example.com']);

// Array
$email->toArray(); // ['address' => 'john@example.com', 'verifiedAt' => null]

// JSON
json_encode($email); // {"address":"john@example.com","verifiedAt":null}

// String
(string) $email; // serialized representation
```

## Nested Value Objects

Value Objects can contain other VOs. The resolution pipeline recursively resolves them:

```php
class UserCredential extends ValueObject
{
    public function __construct(
        public readonly Email $email,
        public readonly Password $password,
    ) {}
}

// Resolve — both Email and Password go through their own pipelines
$credential = UserCredential::resolve([
    'email' => ['address' => 'john@example.com'],
    'password' => ['hash' => 'Secret123!'],
]);

$credential->get('email');    // Email VO instance
$credential->get('password'); // Password VO instance
```

## Reflection cache

The resolution pipeline uses `ReflectionCache` to avoid repeated reflection calls:

```php
use Talred\Domain\Cache\ReflectionCache;

// Cache is populated automatically during resolution
// Metadata (attributes, constructor parameters) is cached after first use
```

This means the first resolution of a VO class has a small overhead, but subsequent resolutions are fast.
