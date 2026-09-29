---
title: Exceptions
description: Domain and REST exception hierarchy with structured error rendering.
navigation:
  title: Exceptions
  order: 8
---

# Exceptions

Talred Forge provides a dual-layer exception system: **domain exceptions** for business logic violations and **REST exceptions** for API error responses. Both layers implement `RenderableExceptionInterface` for structured, consistent error output.

## Exception architecture

```
RenderableExceptionInterface
├── RenderableExceptionTrait
├── BaseException (abstract)
│   ├── RestApiException (abstract)
│   │   ├── BadRequestException        (400)
│   │   ├── UnauthorizedException      (401)
│   │   ├── ForbiddenException         (403)
│   │   ├── NotFoundException           (404)
│   │   ├── ConflictException          (409)
│   │   └── UnprocessableEntityException(422)
│   └── InternalServerErrorException   (500)
└── ValidationException                (422)
```

## RenderableExceptionInterface

All Talred exceptions implement this contract:

```php
namespace Talred\Exceptions\Contracts;

interface RenderableExceptionInterface
{
    public function toErrorArray(): array;
    public function status(): int;
    public function type(): string;
    public function context(): array;
}
```

| Method | Returns | Description |
|--------|---------|-------------|
| `toErrorArray()` | `array` | Structured error for API responses |
| `status()` | `int` | HTTP status code |
| `type()` | `string` | Machine-readable exception type |
| `context()` | `array` | Additional structured context |

## RenderableExceptionTrait

Default implementations for the interface:

```php
use Talred\Exceptions\Traits\RenderableExceptionTrait;

// type() → Returns the short class name via Reflection
// context() → Returns empty array (override in subclasses)
// toErrorArray() → Combines message, type, status, context
```

## BaseException

Abstract base class for all domain and REST exceptions:

```php
namespace Talred\Exceptions;

abstract class BaseException extends \Exception implements RenderableExceptionInterface
{
    use RenderableExceptionTrait;

    public function __construct(
        ?\Throwable $previous = null,
        ?string $errorCode = null,
        array $context = [],
        ?int $status = null,
    );

    abstract protected function exceptionMessage(): string;

    public function status(): int;
    public function getErrorCode(): ?string;
    public function context(): array;
    public function toErrorArray(): array;
}
```

### Custom domain exception

```php
<?php

declare(strict_types=1);

namespace App\Domain\Exceptions;

use Talred\Exceptions\BaseException;

class InsufficientCreditsException extends BaseException
{
    public function __construct(
        private readonly int $required,
        private readonly int $available,
    ) {
        parent::__construct(
            errorCode: 'INSUFFICIENT_CREDITS',
            context: [
                'required' => $this->required,
                'available' => $this->available,
            ],
            status: 422,
        );
    }

    protected function exceptionMessage(): string
    {
        return "Insufficient credits: {$this->required} required, {$this->available} available.";
    }
}
```

Error output:

```json
{
  "message": "Insufficient credits: 100 required, 25 available.",
  "type": "InsufficientCreditsException",
  "status": 422,
  "error_code": "INSUFFICIENT_CREDITS",
  "context": {
    "required": 100,
    "available": 25
  }
}
```

## ValidationException

Specialized exception for validation failures. Always returns HTTP 422.

```php
namespace Talred\Exceptions;

final class ValidationException extends \Exception implements RenderableExceptionInterface
{
    use RenderableExceptionTrait;

    public function __construct(
        array $errors,
    );

    public function getErrors(): array;
    public function status(): int;       // Always 422
    public function toErrorArray(): array;
}
```

### Usage

```php
use Talred\Exceptions\ValidationException;

// Single-field errors
throw new ValidationException([
    'email' => 'Invalid email format.',
    'password' => 'Password must be at least 8 characters.',
]);

// Multi-error format
throw new ValidationException([
    ['field' => 'email', 'message' => 'Invalid email format.'],
    ['field' => 'email', 'message' => 'Email domain not allowed.'],
]);
```

Output:

```json
{
  "message": "Validation failed.",
  "type": "ValidationException",
  "status": 422,
  "context": {
    "errors": {
      "email": "Invalid email format.",
      "password": "Password must be at least 8 characters."
    }
  }
}
```

## REST API exceptions

All REST exceptions extend `RestApiException` which itself extends `BaseException`:

```php
namespace Talred\Exceptions\Rest;

abstract class RestApiException extends BaseException
{
    protected int $statusCode = 400;

    public function status(): int
    {
        return $this->statusCode;
    }
}
```

### BadRequestException (400)

```php
use Talred\Exceptions\Rest\BadRequestException;

throw new BadRequestException();
// → 400 "Bad Request."

throw new BadRequestException(
    errorCode: 'INVALID_PAYLOAD',
    context: ['field' => 'sort'],
);
```

### UnauthorizedException (401)

```php
use Talred\Exceptions\Rest\UnauthorizedException;

throw new UnauthorizedException();
// → 401 "Unauthorized."
```

### ForbiddenException (403)

```php
use Talred\Exceptions\Rest\ForbiddenException;

throw new ForbiddenException();
// → 403 "Forbidden."
```

### NotFoundException (404)

Supports a custom message:

```php
use Talred\Exceptions\Rest\NotFoundException;

throw new NotFoundException();
// → 404 "Resource not found."

throw new NotFoundException('User not found.');
// → 404 "User not found."

throw new NotFoundException(
    customMessage: 'Invoice #1234 not found.',
    errorCode: 'INVOICE_NOT_FOUND',
);
```

### ConflictException (409)

```php
use Talred\Exceptions\Rest\ConflictException;

throw new ConflictException();
// → 409 "Conflict occurred."
```

### UnprocessableEntityException (422)

```php
use Talred\Exceptions\Rest\UnprocessableEntityException;

throw new UnprocessableEntityException();
// → 422 "Unprocessable entity."
```

### InternalServerErrorException (500)

Wraps an internal exception for safe error reporting:

```php
use Talred\Exceptions\Rest\InternalServerErrorException;

try {
    // risky operation
} catch (\Throwable $e) {
    throw new InternalServerErrorException($e);
}
// → 500 "Internal server error"
// Context includes sanitized previous exception info
```

The `context()` method conditionally exposes debug info:

```php
public function context(): array
{
    $context = ['public' => 'An unexpected error occurred.'];

    if ($this->getPrevious()) {
        $context['debug'] = [
            'exception' => get_class($this->getPrevious()),
            'message' => $this->getPrevious()->getMessage(),
        ];
    }

    return $context;
}
```

## Domain-layer exceptions

The domain layer mirrors the same exception hierarchy under `Talred\Domain\Exceptions\*`:

```php
use Talred\Domain\Exceptions\BaseException;
use Talred\Domain\Exceptions\ValidationException;
use Talred\Domain\Exceptions\Contracts\RenderableExceptionInterface;
use Talred\Domain\Exceptions\Traits\RenderableExceptionTrait;
```

Use domain exceptions in entities, value objects, and domain services. Use REST exceptions in controllers and API handlers.

## Error handling in Laravel

Talred exceptions integrate seamlessly with Laravel's exception handler:

```php
// app/Exceptions/Handler.php
use Talred\Exceptions\Contracts\RenderableExceptionInterface;

public function render($request, Throwable $e)
{
    if ($e instanceof RenderableExceptionInterface) {
        return response()->json(
            $e->toErrorArray(),
            $e->status(),
        );
    }

    return parent::render($request, $e);
}
```
