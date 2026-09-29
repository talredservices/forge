---
title: DTO System
description: Structured data transfer objects for application-layer communication.
navigation:
  title: DTOs
  order: 10
---

# DTO System

Talred Forge provides a base DTO (Data Transfer Object) system for structuring data flow between application layers. DTOs ensure type-safe, immutable data exchange between controllers, services, and responses.

## DTO interfaces

### Marker interface

```php
namespace Talred\Support\Application\DTO\Interfaces;

interface DTO
{
    // Marker interface — no methods required
}
```

### InputDTO interface

```php
namespace Talred\Support\Application\DTO\Interfaces;

interface InputDTO extends DTO
{
    public function toArray(): array;
}
```

### ResponseDTO interface

```php
namespace Talred\Support\Application\DTO\Interfaces;

interface ResponseDTO extends DTO
{
    public function toArray(): array;
}
```

## Base classes

### InputDTO

```php
namespace Talred\Support\Application\DTO\Input;

use Talred\Support\Traits\Normalizable;

abstract class InputDTO implements DTO, InputDTOInterface
{
    use Normalizable;
}
```

### ResponseDTO

```php
namespace Talred\Support\Application\DTO\Output;

use Talred\Support\Traits\Normalizable;

abstract class ResponseDTO implements DTO, ResponseDTOInterface
{
    use Normalizable;
}
```

Both base classes use the `Normalizable` trait, which provides automatic `toArray()` conversion.

## Creating DTOs

### Input DTO

```php
<?php

declare(strict_types=1);

namespace App\Application\DTO;

use Talred\Support\Application\DTO\Input\InputDTO;

final readonly class CreateUserInput extends InputDTO
{
    public function __construct(
        public string $name,
        public string $email,
        public string $password,
        public ?string $role = 'user',
    ) {}
}
```

### Response DTO

```php
<?php

declare(strict_types=1);

namespace App\Application\DTO;

use Talred\Support\Application\DTO\Output\ResponseDTO;

final readonly class UserResponse extends ResponseDTO
{
    public function __construct(
        public string $id,
        public string $name,
        public string $email,
        public string $role,
        public string $createdAt,
    ) {}
}
```

## Normalizable trait

The `Normalizable` trait provides automatic object-to-array conversion:

```php
namespace Talred\Support\Traits;

trait Normalizable
{
    public function toArray(): array;

    public static function setNormalizerFactory(callable $factory): void;
}
```

### Normalizer resolution order

1. Custom factory set via `setNormalizerFactory()`
2. `NormalizerInterface` resolved from the Laravel container
3. Default `Talred\Support\Serialization\Normalizer` (Symfony ObjectNormalizer)
4. `RuntimeException` if no normalizer is available

### Custom normalizer factory

```php
use Talred\Support\Traits\Normalizable;

Normalizable::setNormalizerFactory(function () {
    return new CustomNormalizer();
});
```

## Application attributes

### `#[FromRequest]`

Maps a controller/handler parameter to a request field:

```php
namespace Talred\Support\Application\Attributes;

#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
class FromRequest
{
    public function __construct(
        public ?string $field = null,
        public ?string $transformer = null,
    );
}
```

Usage:

```php
class CreateUserHandler
{
    public function __invoke(
        #[FromRequest] CreateUserInput $input,
    ): UserResponse {
        // $input is automatically hydrated from the request
    }
}
```

With field mapping:

```php
class SearchHandler
{
    public function __invoke(
        #[FromRequest(field: 'q')] string $query,
        #[FromRequest(field: 'page', transformer: 'intval')] int $page,
    ): SearchResponse {
        // $query comes from request field 'q'
        // $page is transformed via intval()
    }
}
```

### `#[AsApplicationService]`

Marks a class as an application service for CQRS autoconfiguration:

```php
namespace Talred\Support\Application\Attributes;

#[Attribute(Attribute::TARGET_CLASS)]
class AsApplicationService
{
    // Produces container tag: zolta.cqrs.app_service
}
```

Usage:

```php
use Talred\Support\Application\Attributes\AsApplicationService;

#[AsApplicationService]
class UserRegistrationService
{
    public function register(CreateUserInput $input): UserResponse
    {
        // ...
    }
}
```

## DTO patterns

### Factory from domain entity

```php
final readonly class UserResponse extends ResponseDTO
{
    public function __construct(
        public string $id,
        public string $name,
        public string $email,
    ) {}

    public static function fromEntity(User $user): self
    {
        return new self(
            id: (string) $user->id,
            name: (string) $user->name,
            email: (string) $user->email,
        );
    }
}
```

### Factory from array

```php
final readonly class CreateUserInput extends InputDTO
{
    public function __construct(
        public string $name,
        public string $email,
        public string $password,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            name: $data['name'],
            email: $data['email'],
            password: $data['password'],
        );
    }
}
```

### Collection response

```php
final readonly class UserListResponse extends ResponseDTO
{
    /** @param UserResponse[] $users */
    public function __construct(
        public array $users,
        public int $total,
        public int $page,
    ) {}

    public static function fromPaginated(array $users, int $total, int $page): self
    {
        return new self(
            users: array_map(UserResponse::fromEntity(...), $users),
            total: $total,
            page: $page,
        );
    }
}
```
