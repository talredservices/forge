---
title: Entities & Aggregates
description: Domain entities and aggregate roots with event recording, immutability, and factory patterns.
navigation:
  title: Entities & Aggregates
  order: 2
---

# Entities & Aggregates

Entities represent domain objects with a unique identity that persists over time. Aggregate Roots are entities that serve as consistency boundaries for a cluster of related objects.

## Entity

```php
use Talred\Domain\Entities\Entity;
```

### Base class

```php
abstract class Entity implements EntityInterface
{
    private array $recordedEvents = [];

    protected function recordThat(EventInterface $event): void;
    public function releaseEvents(): array;
    public function __get(string $name): mixed;  // read-only magic accessor
    public function __set(string $name, mixed $value): void; // throws
}
```

### Key behaviors

| Method | Description |
|--------|-------------|
| `recordThat($event)` | Record a domain event (not yet dispatched) |
| `releaseEvents()` | Return and clear all recorded events |
| `__get($name)` | Read-only access to protected/private properties |
| `__set($name, $value)` | Throws — entities are modified only through methods |

### Creating an Entity

```php
<?php

declare(strict_types=1);

namespace App\Domain\Entities;

use Talred\Domain\Entities\Entity;

class OrderLine extends Entity
{
    public function __construct(
        private readonly OrderLineId $id,
        private readonly ProductName $productName,
        private int $quantity,
        private Money $unitPrice,
    ) {}

    public function getId(): OrderLineId { return $this->id; }
    public function getProductName(): ProductName { return $this->productName; }
    public function getQuantity(): int { return $this->quantity; }
    public function getUnitPrice(): Money { return $this->unitPrice; }

    public function adjustQuantity(int $newQuantity): void
    {
        if ($newQuantity < 1) {
            throw new \DomainException('Quantity must be at least 1');
        }
        $this->quantity = $newQuantity;
    }
}
```

## Aggregate Root

```php
use Talred\Domain\Aggregates\AggregateRoot;
```

`AggregateRoot` extends `Entity` and serves as the entry point for a cluster of related objects.

### Pattern: Factory methods

Aggregates use two factory methods:

```php
class Order extends AggregateRoot
{
    private function __construct(
        private readonly OrderId $id,
        private Money $total,
        private string $status,
        private readonly \DateTimeImmutable $createdAt,
    ) {}

    /**
     * Create a NEW aggregate — records domain events.
     */
    public static function create(OrderId $id, Money $total): self
    {
        $order = new self($id, $total, 'pending', new \DateTimeImmutable());
        $order->recordThat(new OrderCreated($id, $total));
        return $order;
    }

    /**
     * Restore from persistence — NO events recorded.
     */
    public static function restore(
        OrderId $id,
        Money $total,
        string $status,
        \DateTimeImmutable $createdAt,
    ): self {
        return new self($id, $total, $status, $createdAt);
    }
}
```

- **`create()`** — Used when creating a new aggregate. Records domain events that represent what happened.
- **`restore()`** — Used when reconstituting from the database. No events are recorded since nothing new happened.

### Domain event recording

```php
// In aggregate methods
public function ship(): void
{
    if ($this->status !== 'paid') {
        throw new \DomainException('Can only ship paid orders');
    }
    $this->status = 'shipped';
    $this->recordThat(new OrderShipped($this->id));
}

public function cancel(): void
{
    $this->status = 'cancelled';
    $this->recordThat(new OrderCancelled($this->id));
}
```

### Releasing events

Events accumulate inside the aggregate until explicitly released:

```php
$order = Order::create($id, $total);
$order->ship();

$events = $order->releaseEvents();
// [OrderCreated, OrderShipped]

$events = $order->releaseEvents();
// [] — cleared after release
```

Events are typically released by the infrastructure layer (repository or application service) and dispatched through the event system.

## Real-world example: User aggregate

From the reference application:

```php
class User extends AggregateRoot
{
    private function __construct(
        private readonly UserId $id,
        private Email $email,
        private Username $username,
        private Password $password,
        private Credit $credit,
        private ?Role $role,
        private Terms $terms,
        private ?AvatarUrl $avatarUrl,
        private readonly \DateTimeImmutable $createdAt,
    ) {}

    public static function create(
        UserId $id,
        Email $email,
        Username $username,
        Password $password,
        Role $role,
        Terms $terms = Terms::declined,
    ): self {
        $user = new self(
            $id, $email, $username, $password,
            Credit::resolve(['amount' => 0.0, 'currency' => 'USD']),
            $role, $terms, null, new \DateTimeImmutable(),
        );
        $user->recordThat(new UserRegisteredEvent($id, $email, $username));
        return $user;
    }

    public function updateEmail(Email $newEmail): void
    {
        $this->email = $newEmail;
        $this->recordThat(new UserEmailUpdated($this->id, $newEmail));
    }

    public function addCredit(float $amount, string $currency): void
    {
        $this->credit = Credit::resolve([
            'amount' => $this->credit->get('amount') + $amount,
            'currency' => $currency,
        ]);
        $this->recordThat(new CreditAdded($this->id, $amount, $currency));
    }
}
```

## Domain Events

Domain events are simple data objects that record what happened. They implement `EventInterface`:

```php
use Talred\Domain\Events\Contracts\EventInterface;

class OrderCreated implements EventInterface
{
    public function __construct(
        public readonly OrderId $orderId,
        public readonly Money $total,
        private readonly \DateTimeImmutable $occurredOn = new \DateTimeImmutable(),
    ) {}

    public function occurredOn(): \DateTimeImmutable
    {
        return $this->occurredOn;
    }
}
```

## Domain Factories

Factories encapsulate complex aggregate creation logic:

```php
class UserFactory
{
    public function create(array $data, Role $role): User
    {
        return User::create(
            new UserId(),
            Email::resolve(['address' => $data['email']]),
            Username::resolve(['username' => $data['username']]),
            Password::resolve(['hash' => $data['password']]),
            $role,
            Terms::from($data['terms'] ?? 'declined'),
        );
    }

    public function restore(array $data): User
    {
        return User::restore(
            new UserId($data['id']),
            Email::resolve(['address' => $data['email'], 'verifiedAt' => $data['email_verified_at']]),
            new Username($data['username']),
            Password::fromHashed($data['password']),
            Credit::resolve(['amount' => $data['credit'], 'currency' => 'USD']),
            // ... relationships
        );
    }
}
```

## Domain Repository interfaces

Repository interfaces live in the domain layer and define the persistence contract:

```php
interface OrderRepository
{
    public function findById(OrderId $id): ?Order;
    public function save(Order $order): void;
    public function delete(Order $order): void;
    public function findAll(AbstractQueryOptions $options): Pagination;
}
```

Implementations (Eloquent, Doctrine, etc.) live in the infrastructure layer.

## Best practices

1. **Private constructors** — Force use of `create()` and `restore()` factory methods
2. **Record events in mutating methods** — Every state change should emit a domain event
3. **No framework dependencies** — Entities and aggregates must be pure PHP classes
4. **Value Objects for all properties** — Prefer typed VOs over primitives
5. **Immutable identity** — The aggregate's ID is set at creation and never changes
6. **Final classes** — Mark aggregates `final` unless you have a specific inheritance need
