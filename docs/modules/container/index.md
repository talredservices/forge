---
title: Container & Registry
description: Framework-agnostic container abstraction and service resolution.
navigation:
  title: Container & Registry
  order: 11
---

# Container & Registry

Talred Forge provides a framework-neutral container system through PSR-11 compatibility. The `ContainerRegistry` acts as a global entry point for resolving framework-bound services while remaining decoupled from any specific framework.

## ZoltaForgeContainer

A PSR-11 compliant wrapper around the host framework's container:

```php
namespace Talred\Support;

use Psr\Container\ContainerInterface;

final readonly class ZoltaForgeContainer implements ContainerInterface
{
    public function __construct(ContainerInterface $container);

    public function get(string $id): mixed;
    public function has(string $id): bool;
}
```

The wrapper checks `has()` first. If the underlying container exposes `hasParameter()` (e.g., Symfony), it falls back to that for parameter lookups.

## ContainerRegistry

Central static registry for the global container instance:

```php
namespace Talred\Support;

use Psr\Container\ContainerInterface;

final class ContainerRegistry
{
    public static function set(ContainerInterface $container): void;
    public static function get(): ZoltaForgeContainer;
    public static function resolve(string $object): mixed;
}
```

### Registering the container

In Laravel, this happens automatically in the service provider:

```php
use Talred\Support\ContainerRegistry;

// In a service provider boot() method
ContainerRegistry::set(app());
```

For other frameworks:

```php
// Symfony
ContainerRegistry::set($container);

// Custom PSR-11 container
ContainerRegistry::set(new MyContainer());
```

### Resolving services

```php
use Talred\Support\ContainerRegistry;
use Talred\Support\Contracts\NormalizerInterface;

// Direct container access
$normalizer = ContainerRegistry::get()->get(NormalizerInterface::class);

// Framework-aware resolution (uses FrameworkRegistry bindings)
$normalizer = ContainerRegistry::resolve(NormalizerInterface::class);
```

### Resolution process

`ContainerRegistry::resolve()` follows this resolution chain:

1. Checks if a container is registered
2. Asks `FrameworkRegistry::resolveBinding()` for the concrete class
3. Throws if no binding is found
4. Gets the implementation from the container
5. Wraps any container exception with contextual information

## Helper functions

Talred provides global helper functions in `helpers.php`:

### `app()`

```php
function app(?string $id = null): mixed|ZoltaForgeContainer
```

Resolves a service from the global container. If no argument is passed, returns the `ZoltaForgeContainer` instance.

```php
// Get the container
$container = app();

// Resolve a service
$logger = app(LoggerInterface::class);
$cache = app('cache');
```

::callout{type="info"}
The `app()` function is only defined if it does not already exist (e.g., Laravel defines its own `app()` helper). In Laravel applications, the native helper takes precedence.
::

### `logger()`

```php
function logger(): ?LoggerInterface
```

Resolves a PSR-3 logger from the container:

```php
$logger = logger();
$logger?->info('Operation completed', ['id' => $userId]);
```

### `zoltaLogger()`

```php
function zoltaLogger(): ?LoggerInterface
```

Framework-agnostic logger resolver with fallback chain:

1. Try resolving `LoggerInterface` from container
2. Try resolving `'logger'` string key
3. Return `null` if neither is available

## BooleanParser

Utility for parsing boolean-like values from strings, form inputs, and configuration:

```php
namespace Talred\Support\Casts;

final class BooleanParser
{
    public static function parse(mixed $value): ?bool;
}
```

### Truthy values

| Input | Result |
|-------|--------|
| `1` | `true` |
| `1.0` | `true` |
| `'1'` | `true` |
| `'true'` | `true` |
| `'on'` | `true` |
| `'yes'` | `true` |
| `true` | `true` |

### Falsy values

| Input | Result |
|-------|--------|
| `0` | `false` |
| `0.0` | `false` |
| `'0'` | `false` |
| `'false'` | `false` |
| `'off'` | `false` |
| `'no'` | `false` |
| `'null'` | `false` |
| `''` | `false` |
| `false` | `false` |

### Ambiguous values

| Input | Result |
|-------|--------|
| `'maybe'` | `null` |
| `42` | `null` |
| `object` | `null` |

### Usage

```php
use Talred\Support\Casts\BooleanParser;

$result = BooleanParser::parse('yes');   // true
$result = BooleanParser::parse('off');   // false
$result = BooleanParser::parse('maybe'); // null

// Useful for query parameters and form inputs
$isActive = BooleanParser::parse($request->query('active')) ?? true;
```

## NormalizerInterface

The contract for DTO-to-array normalization:

```php
namespace Talred\Support\Contracts;

interface NormalizerInterface
{
    public function normalize(object $object): array;
}
```

### Default implementation

```php
namespace Talred\Support\Serialization;

use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;

class Normalizer implements NormalizerInterface
{
    public function normalize(object $object): array
    {
        // Uses Symfony's ObjectNormalizer under the hood
    }
}
```

This normalizer is automatically resolved by the `Normalizable` trait and provides sensible defaults for converting DTOs to arrays.
