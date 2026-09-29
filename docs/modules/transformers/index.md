---
title: Transformers
description: Input normalization before validation in the Value Object resolution pipeline.
navigation:
  title: Transformers
  order: 7
---

# Transformers

Transformers normalize input data **before** validation. They run as the first stage of the Value Object resolution pipeline, ensuring that data is in the correct format before Rules and Specifications evaluate it.

## Transformer interface

```php
use Talred\Domain\Contracts\TransformerInterface;

interface TransformerInterface
{
    public function transform(mixed $value, array $options = []): mixed;
}
```

## Abstract Transformer contract

```php
use Talred\Domain\Contracts\Transformer;

abstract class Transformer implements TransformerInterface
{
    abstract public function transform(mixed $value, array $options = []): mixed;

    public function and(TransformerInterface $transformer): TransformerInterface;
}
```

The `and()` method creates a pipeline: the output of the first transformer becomes the input of the next.

## Using transformers on Value Objects

Attach transformers to properties with `#[Transform]`:

```php
use Talred\Domain\Attributes\Transform;
use Talred\Domain\Transformers\EmailNormalizer;

class Email extends ValueObject
{
    public function __construct(
        #[Transform(EmailNormalizer::class)]
        public readonly string $address,
    ) {}
}
```

Transformers run **before** Rules and Specifications in the resolution pipeline.

## `#[Transform]` attribute

```php
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER, Attribute::IS_REPEATABLE)]
class Transform
{
    public function __construct(
        public string $transformerClass,
        public array $options = [],
    ) {}
}
```

## Built-in transformers

### EmailNormalizer

Trims whitespace and converts to lowercase.

```php
#[Transform(EmailNormalizer::class)]
public readonly string $email;
```

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `trim` | `bool` | `true` | Trim whitespace |
| `lowercase` | `bool` | `true` | Convert to lowercase |

```php
// Input: '  JOHN@EXAMPLE.COM  '
// Output: 'john@example.com'
```

### DateTimeNormalizer

Converts strings to `DateTimeImmutable`.

```php
#[Transform(DateTimeNormalizer::class)]
public readonly \DateTimeImmutable $createdAt;
```

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `format` | `?string` | `null` | Custom date format (e.g., `'Y-m-d'`) |

```php
// String input → DateTimeImmutable
// '2024-01-15 10:30:00' → DateTimeImmutable instance

// DateTimeInterface input → DateTimeImmutable (passed through)
```

### IdentifierNormalizer

Normalizes identifiers (usernames, email-based identifiers).

```php
#[Transform(IdentifierNormalizer::class)]
public readonly string $username;
```

Trims whitespace, lowercases emails, removes extra spaces.

## Composing transformers

Pipeline multiple transformers using `and()`:

```php
$transformer = (new TrimTransformer())
    ->and(new LowercaseTransformer())
    ->and(new StripTagsTransformer());

$result = $transformer->transform('  <b>HELLO</b>  ');
// → 'hello'
```

On a Value Object:

```php
class Bio extends ValueObject
{
    public function __construct(
        #[Transform(TrimTransformer::class)]
        #[Transform(StripTagsTransformer::class)]
        #[UseRule(MaxLengthRule::class, ['max' => 500])]
        public readonly string $value,
    ) {}
}
```

Multiple `#[Transform]` attributes are applied in declaration order.

## Creating custom transformers

```php
<?php

declare(strict_types=1);

namespace App\Domain\Transformers;

use Talred\Domain\Contracts\Transformer;

class SlugTransformer extends Transformer
{
    public function transform(mixed $value, array $options = []): mixed
    {
        if (!is_string($value)) {
            return $value;
        }

        $slug = strtolower(trim($value));
        $slug = preg_replace('/[^a-z0-9\-]/', '-', $slug);
        $slug = preg_replace('/-+/', '-', $slug);

        return trim($slug, '-');
    }
}
```

Usage:

```php
class Slug extends ValueObject
{
    public function __construct(
        #[Transform(SlugTransformer::class)]
        #[UseRule(NonEmptyRule::class)]
        #[UseRule(MaxLengthRule::class, ['max' => 100])]
        public readonly string $value,
    ) {}
}

$slug = Slug::resolve(['value' => 'My Blog Post Title!']);
// → Slug { value: 'my-blog-post-title' }
```

## Runtime preprocessors

You can provide ad-hoc transformers at resolution time via `VOConstructionContext`:

```php
$context = new VOConstructionContext(
    runtimePreprocessors: [
        'address' => fn(string $v) => strtolower(trim($v)),
    ],
);

$email = Email::resolve(['address' => '  TEST@EXAMPLE.COM  '], $context);
```

Runtime preprocessors run **before** `#[Transform]` attributes.
