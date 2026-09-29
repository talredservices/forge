---
title: Specifications
description: Composable boolean business rules for domain validation.
navigation:
  title: Specifications
  order: 4
---

# Specifications

Specifications evaluate boolean business rules against a candidate value. Unlike Rules (which throw on failure), Specifications return `true` or `false` and can provide diagnostic messages.

## Specification interface

```php
use Talred\Domain\Interfaces\Specification;

interface Specification
{
    public function isSatisfiedBy(mixed $candidate): bool;
    public function message(): string;
}
```

## Abstract Specification contract

```php
use Talred\Domain\Contracts\Specification;

abstract class Specification implements SpecificationInterface
{
    abstract public function isSatisfiedBy(mixed $candidate, array $options = []): bool;
    abstract public function message(): string;

    public function and(SpecificationInterface $spec): SpecificationInterface;
    public function or(SpecificationInterface $spec): SpecificationInterface;
}
```

## Using specifications on Value Objects

Attach specifications with `#[UseSpecification]`:

```php
use Talred\Domain\Attributes\UseSpecification;
use Talred\Domain\Specifications\EmailFormatSpecification;
use Talred\Domain\Specifications\AllowedDomainSpecification;

class Email extends ValueObject
{
    public function __construct(
        #[UseSpecification(EmailFormatSpecification::class)]
        #[UseSpecification(AllowedDomainSpecification::class, [
            'allowed' => ['company.com', 'partner.org'],
        ])]
        public readonly string $address,
    ) {}
}
```

When resolution runs, failed specifications throw `InvalidArgumentException` with the spec's `message()`.

## `#[UseSpecification]` attribute

```php
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER, Attribute::IS_REPEATABLE)]
class UseSpecification
{
    public function __construct(
        public string $specClass,
        public array $options = [],
    ) {}
}
```

## Built-in specifications

### EmailFormatSpecification

Validates email format using PHP's `FILTER_VALIDATE_EMAIL`.

```php
#[UseSpecification(EmailFormatSpecification::class)]
public readonly string $email;
```

Message: `"Invalid email format"`

### UuidSpecification

Validates a string is a valid UUID using `Ramsey\Uuid`.

```php
#[UseSpecification(UuidSpecification::class)]
public readonly string $id;
```

Message: `"Invalid UUID format"`

### AllowedDomainSpecification

Validates that an email belongs to an allowed domain.

```php
#[UseSpecification(AllowedDomainSpecification::class, [
    'allowed' => ['example.com', 'company.org'],
])]
public readonly string $email;
```

| Option | Type | Description |
|--------|------|-------------|
| `allowed` | `string[]` | List of allowed email domains |

Message: `"Email domain is not allowed"`

### MinAgeSpecification

Validates a minimum age from a birth date.

```php
#[UseSpecification(MinAgeSpecification::class, ['minAge' => 18])]
public readonly \DateTimeImmutable $birthDate;
```

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `minAge` | `int` | `18` | Minimum age in years |

Message: `"Must be at least {minAge} years old"`

## Composing specifications

### Logical AND

Both must be satisfied:

```php
$spec = (new EmailFormatSpecification())
    ->and(new AllowedDomainSpecification());

$spec->isSatisfiedBy('john@company.com', ['allowed' => ['company.com']]); // true
$spec->isSatisfiedBy('john@unknown.com', ['allowed' => ['company.com']]); // false
```

### Logical OR

At least one must be satisfied:

```php
$spec = (new AllowedDomainSpecification())
    ->or(new AdminEmailSpecification());

// Passes if domain is allowed OR email is an admin email
```

## Creating custom specifications

```php
<?php

declare(strict_types=1);

namespace App\Domain\Specifications;

use Talred\Domain\Contracts\Specification;

class UniqueEmailSpecification extends Specification
{
    public function __construct(
        private readonly UserRepository $userRepository,
    ) {}

    public function isSatisfiedBy(mixed $candidate, array $options = []): bool
    {
        return $this->userRepository->findByEmail($candidate) === null;
    }

    public function message(): string
    {
        return 'Email address is already in use';
    }
}
```

## Using specifications standalone

Specifications work independently of Value Objects:

```php
$spec = new EmailFormatSpecification();

if ($spec->isSatisfiedBy($email)) {
    // valid
} else {
    echo $spec->message(); // "Invalid email format"
}

// Composing for complex checks
$canRegister = (new UniqueEmailSpecification($repo))
    ->and(new AllowedDomainSpecification())
    ->and(new MinAgeSpecification());

if (!$canRegister->isSatisfiedBy($data, ['allowed' => [...], 'minAge' => 18])) {
    echo $canRegister->message();
}
```
