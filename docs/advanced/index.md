---
title: Advanced
description: Internals, extension points, and advanced patterns for Talred Forge.
navigation:
  title: Advanced
  order: 13
---

# Advanced

## Value Object resolution pipeline

Understanding the internal resolution pipeline helps when building custom extensions or debugging unexpected behavior.

### Pipeline stages

```
Input Data
    │
    ▼
1. Runtime Preprocessors  (VOConstructionContext::$runtimePreprocessors)
    │
    ▼
2. Transformers           (#[Transform] attributes, declaration order)
    │
    ▼
3. Rules                  (#[UseRule] attributes — validation + transformation)
    │
    ▼
4. Specifications         (#[UseSpecification] attributes — boolean predicates)
    │
    ▼
5. Construction           (readonly property assignment)
    │
    ▼
6. Invariants             (#[UseInvariant] attributes — post-construction checks)
    │
    ▼
7. Policies               (#[UsePolicy] attributes — cross-field logic)
    │
    ▼
Resolved Value Object
```

### Stage behavior

| Stage | Input | Output | Failure mode |
|-------|-------|--------|-------------|
| Preprocessors | Raw value | Transformed value | Exception propagates |
| Transformers | Value per property | Normalized value | Exception propagates |
| Rules | Value per property | Validated/transformed value | `ValidationException` |
| Specifications | Value per property | Pass/fail | `ValidationException` with message |
| Construction | All resolved values | Object instance | TypeError on mismatch |
| Invariants | Complete VO | Void (pass) | `ValidationException` |
| Policies | Complete VO | Mixed/void | `ValidationException` |

### Skipping resolution

For pre-validated data, skip the entire pipeline:

```php
$context = new VOConstructionContext(skipResolve: true);
$vo = MyValueObject::resolve($data, $context);
```

## Reflection cache

The resolution pipeline uses PHP Reflection to read attributes, constructor parameters, and property metadata. All reflection data is cached in memory for the lifetime of the request.

### Cache behavior

- **First resolution** of a VO class reads attributes via Reflection and caches the metadata
- **Subsequent resolutions** of the same class reuse the cached metadata
- Cache is per-class, stored in static properties of the `VOAutoResolution` trait
- Cache is not persisted across requests (in-memory only)

### Performance implications

```php
// First call: Reflection + cache build (~0.5ms)
$email1 = Email::resolve(['address' => 'a@b.com']);

// Subsequent calls: Cache hit (~0.05ms)
$email2 = Email::resolve(['address' => 'c@d.com']);
$email3 = Email::resolve(['address' => 'e@f.com']);
```

For hot paths resolving thousands of VOs, the cache ensures near-zero overhead after the first instance.

## Composition patterns

### Rule composition

```php
use Talred\Domain\Contracts\Rule;

// AND composition — both rules must pass
$rule = (new NonEmptyRule())->and(new MaxLengthRule());

// OR composition — try left, fallback to right
$rule = (new UuidRule())->or(new SlugRule());
```

### Specification composition

```php
use Talred\Domain\Contracts\Specification;

// AND — both must be satisfied
$spec = (new EmailFormatSpecification())->and(new AllowedDomainSpecification());

// OR — at least one must be satisfied
$spec = (new InternalEmailSpec())->or(new WhitelistedEmailSpec());

// NOT — negate a specification
$spec = (new BlacklistedDomainSpec())->not();

// Complex combination
$spec = (new EmailFormatSpecification())
    ->and(
        (new InternalEmailSpec())->or(new WhitelistedEmailSpec()),
    )
    ->and((new BlacklistedDomainSpec())->not());
```

### Invariant composition

```php
use Talred\Domain\Contracts\Invariant;

// AND — both invariants must hold
$invariant = (new BalanceInvariant())->and(new CurrencyInvariant());

// OR — at least one must hold
$invariant = (new StandardCreditInvariant())->or(new PromotionalCreditInvariant());
```

### Policy composition

```php
use Talred\Domain\Contracts\Policy;

// AND — both policies applied sequentially
$policy = (new EmailPolicy())->and(new PasswordPolicy());
```

## Creating a framework adapter

To support a new framework, implement `FrameworkAdapterInterface`:

```php
<?php

declare(strict_types=1);

namespace Zolta\Adapters\Slim;

use Talred\Framework\FrameworkAdapterInterface;

class SlimAdapter implements FrameworkAdapterInterface
{
    public static function supports(): bool
    {
        return class_exists(\Slim\App::class);
    }

    public static function priority(): int
    {
        return 50; // Lower than Laravel (100)
    }

    public static function bindings(): array
    {
        return [
            \Talred\Support\Contracts\NormalizerInterface::class
                => \Talred\Support\Serialization\Normalizer::class,
            \Psr\Log\LoggerInterface::class
                => \Monolog\Logger::class,
        ];
    }
}
```

Register via Composer metadata:

```json
{
    "extra": {
        "zolta-framework-adapter": "Zolta\\Adapters\\Slim\\SlimAdapter"
    }
}
```

Then bootstrap in your application entry point:

```php
use Talred\Framework\FrameworkBootstrap;
use Talred\Support\ContainerRegistry;

FrameworkBootstrap::boot();
ContainerRegistry::set($container); // Your PSR-11 container
```

## Building a custom Value Object pipeline

For advanced scenarios, you can implement custom auto-resolution by overriding the resolve pattern:

```php
class Money extends ValueObject
{
    public function __construct(
        #[UseRule(PositiveNumberRule::class)]
        public readonly float $amount,

        #[UseRule(NonEmptyRule::class)]
        public readonly string $currency,
    ) {}

    public static function resolve(
        array $data,
        ?VOConstructionContext $context = null,
    ): static {
        // Pre-processing before standard resolution
        if (isset($data['formatted'])) {
            // Parse "$100.00 USD" format
            preg_match('/^\$?([\d.]+)\s*(\w{3})$/', $data['formatted'], $m);
            $data = ['amount' => (float) $m[1], 'currency' => $m[2]];
        }

        return parent::resolve($data, $context);
    }

    // Domain operations
    public function add(Money $other): self
    {
        assert($this->currency === $other->currency);
        return self::resolve([
            'amount' => $this->amount + $other->amount,
            'currency' => $this->currency,
        ]);
    }
}
```

## Entity read-only access

Entities use `__get()` for read-only property access with getter method fallback:

```php
class User extends AggregateRoot
{
    protected readonly UserId $id;
    protected Email $email;

    // Custom getter
    protected function getDisplayName(): string
    {
        return "{$this->name} <{$this->email}>";
    }
}

// Access protected properties (read-only)
$user->id;          // → UserId instance
$user->email;       // → Email instance
$user->displayName; // → Calls getDisplayName()

// Write is forbidden
$user->email = $newEmail; // → LogicException
```

The `__isset()` method returns `true` for both properties and getters, enabling safe null-coalescing:

```php
$name = $user->displayName ?? 'Anonymous';
```

## Domain event lifecycle

```php
// 1. Record events during behavior
$user = User::create($id, $name, $email, $password);
// → UserCreatedEvent recorded internally

$user->changeEmail($newEmail);
// → UserEmailChangedEvent recorded internally

// 2. Release events after persistence
$repository->save($user);
$events = $user->releaseEvents();
// → [UserCreatedEvent, UserEmailChangedEvent]
// → Internal buffer cleared

// 3. Dispatch via event bus (zolta-cqrs)
foreach ($events as $event) {
    $eventBus->dispatch($event);
}
```

## Debugging resolution failures

When a Value Object fails to resolve, the exception includes context:

```php
try {
    $email = Email::resolve(['address' => '']);
} catch (ValidationException $e) {
    $e->getErrors();
    // → ['address' => 'Value must not be empty.']

    $e->toErrorArray();
    // → [
    //     'message' => 'Validation failed.',
    //     'type' => 'ValidationException',
    //     'status' => 422,
    //     'context' => ['errors' => ['address' => 'Value must not be empty.']]
    // ]
}
```

For specification failures, the `message()` method provides context:

```php
try {
    $email = Email::resolve(['address' => 'not-an-email']);
} catch (ValidationException $e) {
    $e->getErrors();
    // → ['address' => 'The given value does not satisfy the email format specification.']
}
```
