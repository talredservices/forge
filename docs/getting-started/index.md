---
title: Getting Started
description: Install and configure Talred Forge in your PHP application.
navigation:
  title: Getting Started
  order: 1
---

# Getting Started

## Installation

Install via Composer:

```bash
composer require talred/forge
```

Use `Talred\...` namespaces in new code. Existing `Zolta\...` imports remain
supported by the compatibility layer while the package is migrated in stages.

### Laravel

Talred Forge auto-discovers its framework adapter via Composer metadata. No manual service provider registration is needed.

The adapter provides:

- Framework binding resolution for domain abstractions
- PSR-11 container bridge
- Serialization and normalization support

### Symfony

Symfony support is partially available. Register the adapter manually in your kernel or bundle configuration. Full Symfony support is planned for a future release.

## Project structure

Talred Forge encourages a layered architecture. A typical service domain follows this layout:

```
app/
└── Services/
    └── OrderService/
        ├── Domain/
        │   ├── Aggregates/
        │   │   └── Order.php
        │   ├── Entities/
        │   │   └── OrderLine.php
        │   ├── ValueObjects/
        │   │   ├── OrderId.php
        │   │   ├── Money.php
        │   │   └── OrderStatus.php
        │   ├── Events/
        │   │   ├── OrderCreated.php
        │   │   └── OrderShipped.php
        │   ├── Specifications/
        │   │   └── MinimumOrderAmountSpec.php
        │   ├── Factories/
        │   │   └── OrderFactory.php
        │   └── Repositories/
        │       └── OrderRepository.php  (interface)
        └── Application/
            ├── DTOs/
            │   ├── Input/
            │   │   └── CreateOrderDTO.php
            │   └── Output/
            │       └── OrderResponseDTO.php
            └── Services/
                └── CreateOrderService.php
```

The **Domain** layer contains pure business logic with no framework dependencies. The **Application** layer orchestrates use cases and maps between domain and infrastructure.

## Creating your first Value Object

### 1. Define the Value Object

```php
<?php

declare(strict_types=1);

namespace App\Services\OrderService\Domain\ValueObjects;

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

    public function toString(): string
    {
        return $this->value;
    }
}
```

### 2. Resolve it from data

```php
// From raw data — auto-validates
$name = ProductName::resolve(['value' => 'Mechanical Keyboard']);

// Access the value
echo $name->get('value'); // "Mechanical Keyboard"
echo $name->toString();   // "Mechanical Keyboard"

// Serialize
$array = $name->toArray(); // ['value' => 'Mechanical Keyboard']

// Equality
$other = ProductName::resolve(['value' => 'Mechanical Keyboard']);
$name->equals($other); // true
```

### 3. Invalid data is rejected immediately

```php
// Throws InvalidArgumentException: "Value must not be empty"
ProductName::resolve(['value' => '']);

// Throws InvalidArgumentException: "Value must not exceed 200 characters"
ProductName::resolve(['value' => str_repeat('x', 201)]);
```

## Creating a UUID identifier

```php
<?php

declare(strict_types=1);

namespace App\Services\OrderService\Domain\ValueObjects;

use Talred\Domain\ValueObjects\AbstractUuid;

class OrderId extends AbstractUuid {}
```

That's it. `AbstractUuid` provides:

```php
// Auto-generate a new UUID v4
$id = new OrderId();

// From existing string
$id = OrderId::fromString('550e8400-e29b-41d4-a716-446655440000');

// Access value
echo $id->get('value'); // "550e8400-e29b-41d4-a716-446655440000"
echo $id->toString();   // "550e8400-e29b-41d4-a716-446655440000"
```

## Creating an Entity

```php
<?php

declare(strict_types=1);

namespace App\Services\OrderService\Domain\Aggregates;

use Talred\Domain\Aggregates\AggregateRoot;
use App\Services\OrderService\Domain\ValueObjects\OrderId;
use App\Services\OrderService\Domain\ValueObjects\Money;
use App\Services\OrderService\Domain\Events\OrderCreated;

class Order extends AggregateRoot
{
    private function __construct(
        private readonly OrderId $id,
        private Money $total,
        private string $status,
    ) {}

    public static function create(OrderId $id, Money $total): self
    {
        $order = new self($id, $total, 'pending');
        $order->recordThat(new OrderCreated($id, $total));
        return $order;
    }

    public static function restore(OrderId $id, Money $total, string $status): self
    {
        return new self($id, $total, $status);
    }

    public function getId(): OrderId { return $this->id; }
    public function getTotal(): Money { return $this->total; }
    public function getStatus(): string { return $this->status; }
}
```

## Next steps

- [Value Objects](/forge/modules/value-objects) — Complete guide to the VO system
- [Rules](/forge/modules/rules) — All built-in validation rules
- [Entities & Aggregates](/forge/modules/entities) — Domain modeling patterns
- [Examples](/forge/examples) — Real-world usage from the reference application
