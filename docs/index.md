---
title: Talred Forge
description: Domain-Driven Design foundation for PHP 8.2+ — Value Objects, Entities, Aggregates, Rules, Specifications, Policies, Invariants, and Transformers.
navigation:
  title: Introduction
  order: 0
---

# Talred Forge

Talred Forge is the foundational layer of the **Talred Framework** — a production-grade Domain-Driven Design (DDD) toolkit for PHP 8.2+. It provides the building blocks to model rich, validated, self-protecting domain objects using a declarative, attribute-driven approach.

The public namespace is `Talred\...`. It is an additive compatibility layer
over the existing `Zolta\...` implementation, so current applications can
keep their Zolta imports while new code adopts Talred imports.

## Why Talred Forge?

Modern PHP applications deserve a domain modeling layer that is:

- **Declarative** — Define validation, transformation, and invariants directly on your domain objects using PHP attributes.
- **Self-protecting** — Value Objects validate, transform, and enforce business rules at construction time. Invalid state is impossible.
- **Composable** — Rules, Specifications, Policies, Invariants, and Transformers compose freely using `->and()` and `->or()` operators.
- **Framework-agnostic** — The domain layer has zero coupling to Laravel, Symfony, or any infrastructure. Adapter resolution happens automatically at runtime.
- **Type-safe** — Leverages PHP 8.2+ readonly properties, enums, and strict typing throughout.

## Core principles

Talred Forge follows these architectural principles:

1. **Hexagonal Architecture** — Domain logic is isolated from infrastructure. Adapters connect the domain to frameworks and databases.
2. **Domain-Driven Design** — Aggregate Roots, Entities, Value Objects, Domain Events, Specifications, and Policies are first-class citizens.
3. **Attribute-Driven Configuration** — No XML, no YAML config files for domain rules. Everything is declared with PHP 8 attributes on the classes themselves.
4. **Fail-Fast Validation** — Invalid input is rejected at construction time, never at persistence time.

## What Talred Forge provides

| Module | Purpose |
|--------|---------|
| [Value Objects](/forge/modules/value-objects) | Immutable domain primitives with auto-resolution pipeline |
| [Entities & Aggregates](/forge/modules/entities) | Domain entities with event recording and immutability |
| [Rules](/forge/modules/rules) | Guard-style validation constraints |
| [Specifications](/forge/modules/specifications) | Composable boolean business rules |
| [Policies](/forge/modules/policies) | Post-construction behavioral logic |
| [Invariants](/forge/modules/invariants) | Class-level structural guarantees |
| [Transformers](/forge/modules/transformers) | Input normalization before validation |
| [Exceptions](/forge/modules/exceptions) | Structured, renderable domain and REST exceptions |
| [Framework Adapter](/forge/modules/framework) | Auto-discovery and runtime binding across frameworks |
| [DTO System](/forge/modules/dto) | Input and Response DTOs for application layer |
| [Container & Registry](/forge/modules/container) | PSR-11 container integration and service resolution |

## The Value Object resolution pipeline

The centerpiece of Talred Forge is the **automatic VO resolution pipeline**. When you construct a Value Object, the framework executes a multi-stage pipeline:

```
Input Data
  ↓
[1] Preprocessors        — Runtime-provided callables
  ↓
[2] #[Transform]         — Normalize before validation
  ↓
[3] #[UseRule]           — Validate constraints
  ↓
[4] #[UseSpecification]  — Enforce domain specifications
  ↓
[5] Nested VO Resolution — Recursively resolve child VOs
  ↓
[6] #[UseInvariant]      — Enforce class-level invariants
  ↓
[7] #[UsePolicy]         — Apply post-construction policies
  ↓
Fully validated VO instance
```

Every stage is opt-in — attach only the attributes you need.

## Quick example

```php
use Talred\Domain\ValueObjects\ValueObject;
use Talred\Domain\Attributes\Transform;
use Talred\Domain\Attributes\UseRule;
use Talred\Domain\Attributes\UseSpecification;
use Talred\Domain\Rules\NonEmptyRule;
use Talred\Domain\Rules\MaxLengthRule;
use Talred\Domain\Transformers\EmailNormalizer;
use Talred\Domain\Specifications\EmailFormatSpecification;

class Email extends ValueObject
{
    public function __construct(
        #[Transform(EmailNormalizer::class)]
        #[UseRule(NonEmptyRule::class)]
        #[UseRule(MaxLengthRule::class, ['max' => 254])]
        #[UseSpecification(EmailFormatSpecification::class)]
        public readonly string $address,

        public readonly ?\DateTimeImmutable $verifiedAt = null,
    ) {}

    public function isVerified(): bool
    {
        return $this->verifiedAt !== null
            && $this->verifiedAt <= new \DateTimeImmutable();
    }
}

// Usage — auto-validates, transforms, and enforces specs
$email = Email::resolve(['address' => '  John@Example.COM  ']);
// → Email { address: 'john@example.com', verifiedAt: null }
```

## Talred Framework ecosystem

Talred Forge is one of three packages in the Talred Framework:

| Package | Purpose |
|---------|---------|
| **Talred Forge** | DDD foundation — Value Objects, Entities, Rules, Specifications |
| [Talred CQRS](/cqrs) | Command/Query buses, Application Services, Repositories, Events |
| [Talred HTTP](/http) | Attribute-based HTTP routing, Request validation, API responses |

## Requirements

- PHP 8.2+
- Composer
- Laravel 10+ (for Laravel adapter) or Symfony 6+ (partial, future)
