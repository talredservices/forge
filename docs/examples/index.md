---
title: Examples
description: Real-world examples from the Talred reference application.
navigation:
  title: Examples
  order: 12
---

# Examples

These examples are drawn from the Talred reference application and demonstrate real-world usage of the framework.

## User aggregate

A complete User aggregate root with value objects, domain events, and factories.

### Domain model

```php
<?php

declare(strict_types=1);

namespace App\Domain\Aggregates;

use App\Domain\Events\UserCreatedEvent;
use App\Domain\Events\UserEmailChangedEvent;
use App\Domain\ValueObjects\Email;
use App\Domain\ValueObjects\HashedPassword;
use App\Domain\ValueObjects\UserId;
use App\Domain\ValueObjects\Username;
use Talred\Domain\Aggregates\AggregateRoot;

class User extends AggregateRoot
{
    protected function __construct(
        protected readonly UserId $id,
        protected readonly Username $name,
        protected Email $email,
        protected HashedPassword $password,
        protected readonly \DateTimeImmutable $createdAt,
    ) {}

    // Factory: create a new user
    public static function create(
        UserId $id,
        Username $name,
        Email $email,
        HashedPassword $password,
    ): self {
        $user = new self(
            id: $id,
            name: $name,
            email: $email,
            password: $password,
            createdAt: new \DateTimeImmutable(),
        );

        $user->recordThat(new UserCreatedEvent(
            userId: (string) $id,
            email: (string) $email,
        ));

        return $user;
    }

    // Factory: restore from persistence
    public static function restore(
        UserId $id,
        Username $name,
        Email $email,
        HashedPassword $password,
        \DateTimeImmutable $createdAt,
    ): self {
        return new self(
            id: $id,
            name: $name,
            email: $email,
            password: $password,
            createdAt: $createdAt,
        );
    }

    // Behavior
    public function changeEmail(Email $newEmail): void
    {
        $oldEmail = $this->email;
        $this->email = $newEmail;

        $this->recordThat(new UserEmailChangedEvent(
            userId: (string) $this->id,
            oldEmail: (string) $oldEmail,
            newEmail: (string) $newEmail,
        ));
    }
}
```

### Value objects

```php
// UserId — UUID value object
class UserId extends ValueObject
{
    public function __construct(
        #[UseRule(UuidRule::class)]
        public readonly string $value,
    ) {}

    public static function generate(): self
    {
        return self::resolve(['value' => (string) Uuid::uuid4()]);
    }

    public function __toString(): string
    {
        return $this->value;
    }
}

// Email — Normalized and validated
class Email extends ValueObject
{
    public function __construct(
        #[Transform(EmailNormalizer::class)]
        #[UseRule(NonEmptyRule::class)]
        #[UseSpecification(EmailFormatSpecification::class)]
        public readonly string $address,
    ) {}

    public function __toString(): string
    {
        return $this->address;
    }
}

// Username — Length-constrained
class Username extends ValueObject
{
    public function __construct(
        #[UseRule(NonEmptyRule::class)]
        #[UseRule(MaxLengthRule::class, ['max' => 50])]
        public readonly string $value,
    ) {}

    public function __toString(): string
    {
        return $this->value;
    }
}

// HashedPassword — Pre-hashed storage
class HashedPassword extends ValueObject
{
    public function __construct(
        #[UseRule(NonEmptyRule::class)]
        public readonly string $hash,
    ) {}

    public static function fromPlaintext(string $password): self
    {
        return self::resolve([
            'hash' => password_hash($password, PASSWORD_ARGON2ID),
        ]);
    }

    public function verify(string $plaintext): bool
    {
        return password_verify($plaintext, $this->hash);
    }
}
```

### Domain events

```php
use Talred\Domain\Events\Contracts\EventInterface;

final readonly class UserCreatedEvent implements EventInterface
{
    public function __construct(
        public string $userId,
        public string $email,
        public \DateTimeImmutable $occurredAt = new \DateTimeImmutable(),
    ) {}

    public function eventName(): string
    {
        return 'user.created';
    }
}
```

### Repository interface

```php
namespace App\Domain\Repositories;

interface UserRepositoryInterface
{
    public function findById(UserId $id): ?User;
    public function findByEmail(Email $email): ?User;
    public function save(User $user): void;
    public function delete(UserId $id): void;
}
```

## Permission system

A complete permission management example combining the domain and application layers.

### Domain model

```php
// Permission value object
class PermissionName extends ValueObject
{
    public function __construct(
        #[UseRule(NonEmptyRule::class)]
        #[UseRule(PermissionNameRule::class)]
        #[UseRule(MaxLengthRule::class, ['max' => 100])]
        public readonly string $value,
    ) {}
}

// Role entity
class Role extends Entity
{
    protected function __construct(
        protected readonly RoleId $id,
        protected RoleName $name,
        /** @var PermissionName[] */
        protected array $permissions = [],
    ) {}

    public static function create(RoleId $id, RoleName $name): self
    {
        return new self(id: $id, name: $name);
    }

    public function grantPermission(PermissionName $permission): void
    {
        foreach ($this->permissions as $existing) {
            if ($existing->equals($permission)) {
                return; // idempotent
            }
        }
        $this->permissions[] = $permission;
    }

    public function revokePermission(PermissionName $permission): void
    {
        $this->permissions = array_values(
            array_filter(
                $this->permissions,
                fn(PermissionName $p) => !$p->equals($permission),
            ),
        );
    }

    public function hasPermission(PermissionName $permission): bool
    {
        foreach ($this->permissions as $existing) {
            if ($existing->equals($permission)) {
                return true;
            }
        }
        return false;
    }
}
```

### Application service

```php
use Talred\Support\Application\Attributes\AsApplicationService;

#[AsApplicationService]
class PermissionService
{
    public function __construct(
        private readonly RoleRepositoryInterface $roleRepository,
    ) {}

    public function grantPermission(string $roleId, string $permission): void
    {
        $role = $this->roleRepository->findById(
            RoleId::resolve(['value' => $roleId]),
        );

        if (!$role) {
            throw new NotFoundException("Role not found.");
        }

        $role->grantPermission(
            PermissionName::resolve(['value' => $permission]),
        );

        $this->roleRepository->save($role);
    }
}
```

## E-commerce credit system

Demonstrates the use of invariants and specifications for business constraints.

### Value objects with invariants

```php
#[UseInvariant(CreditInvariant::class)]
class Credit extends ValueObject
{
    public function __construct(
        #[UseRule(PositiveNumberRule::class)]
        public readonly float $amount,

        #[UseRule(NonEmptyRule::class)]
        public readonly string $currency,
    ) {}

    public function add(Credit $other): self
    {
        if ($this->currency !== $other->currency) {
            throw new \DomainException('Cannot add credits with different currencies.');
        }

        return self::resolve([
            'amount' => $this->amount + $other->amount,
            'currency' => $this->currency,
        ]);
    }

    public function subtract(Credit $other): self
    {
        if ($this->currency !== $other->currency) {
            throw new \DomainException('Cannot subtract credits with different currencies.');
        }

        return self::resolve([
            'amount' => $this->amount - $other->amount,
            'currency' => $this->currency,
        ]);
    }
}
```

### Specification-based filtering

```php
use Talred\Domain\Contracts\Specification;

class MinimumBalanceSpecification extends Specification
{
    public function __construct(
        private readonly float $minimum = 0.0,
    ) {}

    public function isSatisfiedBy(mixed $candidate, array $options = []): bool
    {
        return $candidate instanceof Credit
            && $candidate->amount >= $this->minimum;
    }

    public function message(): string
    {
        return "Credit balance must be at least {$this->minimum}.";
    }
}

// Compose specifications
$eligibleForPremium = (new MinimumBalanceSpecification(100.0))
    ->and(new ActiveAccountSpecification())
    ->and((new SuspendedSpecification())->not());

if ($eligibleForPremium->isSatisfiedBy($userCredit)) {
    // Grant premium access
}
```

## Value Object resolution patterns

### Basic resolution

```php
// From array data
$email = Email::resolve(['address' => 'user@example.com']);

// From validated request
$input = CreateUserInput::fromArray($request->validated());
```

### Resolution with context

```php
$context = new VOConstructionContext(
    runtimePreprocessors: [
        'address' => fn(string $v) => strtolower(trim($v)),
    ],
    runtimeOptions: [
        'strict' => true,
    ],
);

$email = Email::resolve(['address' => '  USER@EXAMPLE.COM  '], $context);
// → Email { address: 'user@example.com' }
```

### Skip resolution

```php
// Bypass the resolution pipeline (for pre-validated data)
$context = new VOConstructionContext(skipResolve: true);
$id = UserId::resolve(['value' => $trustedUuid], $context);
```

### Equality checks

```php
$email1 = Email::resolve(['address' => 'user@example.com']);
$email2 = Email::resolve(['address' => 'USER@EXAMPLE.COM']);

$email1->equals($email2); // true — normalization makes them equal
```

### Serialization

```php
$email = Email::resolve(['address' => 'user@example.com']);

// To array
$email->toArray(); // ['address' => 'user@example.com']

// JSON
json_encode($email); // {"address":"user@example.com"}

// String
(string) $email; // 'user@example.com' (via __toString)
```
