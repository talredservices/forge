---
title: Rules
description: Guard-style validation constraints for Value Object properties.
navigation:
  title: Rules
  order: 3
---

# Rules

Rules are guard-style validators that enforce constraints on individual values. They throw `InvalidArgumentException` when validation fails, ensuring that invalid data never enters a Value Object.

## Rule interface

```php
use Talred\Domain\Interfaces\Rule;

interface Rule
{
    public function validate(mixed $value): void;
}
```

## Abstract Rule contract

The `Rule` abstract contract provides composition and an `apply()` method:

```php
use Talred\Domain\Contracts\Rule;

abstract class Rule implements RuleInterface
{
    abstract public function validate(mixed $value, array $options = []): void;

    public function apply(mixed $value, array $options = []): mixed;
    public function and(RuleInterface $rule): RuleInterface;
    public function or(RuleInterface $rule): RuleInterface;
}
```

| Method | Description |
|--------|-------------|
| `validate($value, $options)` | Check value, throw on failure |
| `apply($value, $options)` | Validate and return the value |
| `and($rule)` | Compose: run this rule, then the next (both must pass) |
| `or($rule)` | Compose: if this fails, try the other |

## Using rules on Value Objects

Attach rules to constructor properties with `#[UseRule]`:

```php
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

Rules are executed in declaration order during `resolve()`.

## `#[UseRule]` attribute

```php
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER, Attribute::IS_REPEATABLE)]
class UseRule
{
    public function __construct(
        public string $ruleClass,    // Rule class name
        public array $options = [],  // Options passed to validate()
    ) {}
}
```

## Built-in rules

### NonEmptyRule

Ensures a value is not empty (null, empty string, empty array).

```php
#[UseRule(NonEmptyRule::class)]
public readonly string $name;
```

Throws: `"Value must not be empty"`

### MaxLengthRule

Enforces a maximum string length.

```php
#[UseRule(MaxLengthRule::class, ['max' => 255])]
public readonly string $title;
```

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `max` | `int` | `256` | Maximum character count |

Throws: `"Value must not exceed {max} characters"`

### PositiveNumberRule

Ensures a numeric value is non-negative.

```php
#[UseRule(PositiveNumberRule::class)]
public readonly float $amount;
```

Throws: `"Value must be a non-negative number"`

### UuidRule

Validates a string is a valid UUID format.

```php
#[UseRule(UuidRule::class)]
public readonly string $id;
```

Throws: `"Value must be a valid UUID"`

### RegexRule

Validates against a custom regular expression.

```php
#[UseRule(RegexRule::class, ['pattern' => '/^[A-Z]{3}$/'])]
public readonly string $currencyCode;
```

| Option | Type | Description |
|--------|------|-------------|
| `pattern` | `string` | The regex pattern to match |

Throws: `"Value does not match the required format"`

### PasswordPolicyRule

Enforces password strength requirements.

```php
#[UseRule(PasswordPolicyRule::class, ['minLength' => 8])]
public readonly string $password;
```

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `minLength` | `int` | `8` | Minimum password length |

Validates: length, presence of numbers, special characters.

### PermissionNameRule

Validates permission name format (lowercase, dots, underscores).

```php
#[UseRule(PermissionNameRule::class)]
public readonly string $name;
```

Pattern: `/^[a-z][a-z0-9_\.]{2,99}$/`

Throws: `"Permission name must start with a letter and contain only lowercase letters, digits, underscores, or dots (3-100 chars)"`

## Composing rules

### Sequential (AND)

Both rules must pass:

```php
$rule = (new NonEmptyRule())->and(new MaxLengthRule());
$rule->validate('hello', ['max' => 100]); // passes
$rule->validate('', ['max' => 100]);      // fails at NonEmptyRule
```

### Fallback (OR)

If the first rule fails, try the second:

```php
$rule = (new UuidRule())->or(new RegexRule());
$rule->validate('550e8400-...'); // passes (UUID)
$rule->validate('CUSTOM-001', ['pattern' => '/^CUSTOM-\d+$/']); // passes (regex)
```

## Creating custom rules

```php
<?php

declare(strict_types=1);

namespace App\Domain\Rules;

use Talred\Domain\Contracts\Rule;

class MinimumAmountRule extends Rule
{
    public function validate(mixed $value, array $options = []): void
    {
        $min = $options['min'] ?? 0;

        if (!is_numeric($value) || $value < $min) {
            throw new \InvalidArgumentException(
                "Value must be at least {$min}"
            );
        }
    }
}
```

Usage:

```php
class OrderTotal extends ValueObject
{
    public function __construct(
        #[UseRule(MinimumAmountRule::class, ['min' => 1.00])]
        public readonly float $amount,
    ) {}
}
```

## Using rules outside Value Objects

Rules can be used standalone:

```php
$rule = new NonEmptyRule();
$rule->validate('hello');  // passes
$rule->validate('');       // throws InvalidArgumentException

$rule = new MaxLengthRule();
$rule->validate('hello', ['max' => 10]); // passes
```
