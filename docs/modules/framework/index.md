---
title: Framework Adapter
description: Framework-agnostic adapter discovery and binding resolution system.
navigation:
  title: Framework Adapter
  order: 9
---

# Framework Adapter

Talred Forge is designed to run on **any PHP framework**. The Framework Adapter module discovers available adapters at boot time using Composer metadata and resolves bindings through a priority-based registry. The `zolta-framework-adapter` metadata key is retained as a technical compatibility key.

## Architecture overview

```
Composer metadata (extra.zolta-framework-adapter)
        │
        ▼
  FrameworkBootstrap::boot()
        │
        ▼
  FrameworkRegistry::register()
        │
        ▼
  FrameworkRegistry::resolveBinding()
        │
        ▼
  ContainerRegistry::resolve()
```

## FrameworkAdapterInterface

Every adapter implements this contract:

```php
namespace Talred\Framework;

interface FrameworkAdapterInterface
{
    public static function supports(): bool;
    public static function priority(): int;
    public static function bindings(): array;
}
```

| Method | Returns | Description |
|--------|---------|-------------|
| `supports()` | `bool` | Whether the current runtime matches this adapter |
| `priority()` | `int` | Higher values win when multiple adapters compete |
| `bindings()` | `array` | Map of `abstraction => implementation` class names |

### Creating an adapter

```php
<?php

declare(strict_types=1);

namespace Zolta\Adapters\Laravel;

use Talred\Framework\FrameworkAdapterInterface;

class LaravelAdapter implements FrameworkAdapterInterface
{
    public static function supports(): bool
    {
        return class_exists(\Illuminate\Foundation\Application::class);
    }

    public static function priority(): int
    {
        return 100;
    }

    public static function bindings(): array
    {
        return [
            \Talred\Support\Contracts\NormalizerInterface::class
                => \Talred\Support\Serialization\Normalizer::class,
            \Psr\Log\LoggerInterface::class
                => \Illuminate\Log\LogManager::class,
        ];
    }
}
```

## FrameworkBootstrap

Discovers adapters from Composer package metadata and boots the framework layer.

```php
namespace Talred\Framework;

final class FrameworkBootstrap
{
    public const EXTRA_KEY = 'zolta-framework-adapter';

    public static function boot(): void;
}
```

### Discovery process

1. Reads all Composer `installed.json` and `installed-packages.json` files
2. Uses `\Composer\InstalledVersions` as a fallback
3. Searches for packages with `extra.zolta-framework-adapter` metadata
4. Supports both string and array values for adapter class names
5. Registers discovered adapters with `FrameworkRegistry`

### Composer metadata

Register your adapter in `composer.json`:

```json
{
    "name": "talred/forge-laravel-adapter",
    "extra": {
        "zolta-framework-adapter": "Zolta\\Adapters\\Laravel\\LaravelAdapter"
    }
}
```

Multiple adapters per package:

```json
{
    "extra": {
        "zolta-framework-adapter": [
            "Zolta\\Adapters\\Laravel\\LaravelAdapter",
            "Zolta\\Adapters\\Laravel\\LaravelCacheAdapter"
        ]
    }
}
```

## FrameworkRegistry

Central registry that tracks adapters and resolves bindings.

```php
namespace Talred\Framework;

final class FrameworkRegistry
{
    public static function register(string $adapter): void;
    public static function resolveBinding(string $abstraction): ?string;
    public static function resolve(): ?string;
}
```

### Binding resolution

1. Calls `FrameworkBootstrap::boot()` to discover adapters
2. Filters adapters by `supports()` — only compatible adapters are kept
3. Sorts by `priority()` descending — highest priority wins
4. Returns the first matching adapter's binding for the given abstraction
5. Caches results to avoid redundant lookups

```php
use Talred\Framework\FrameworkRegistry;

// Resolve which class implements NormalizerInterface
$concrete = FrameworkRegistry::resolveBinding(
    \Talred\Support\Contracts\NormalizerInterface::class,
);
// → 'Talred\Support\Serialization\Normalizer'
```

### Adapter priority

When multiple adapters are available, `priority()` determines which wins:

```php
// LaravelAdapter::priority() → 100
// SymfonyAdapter::priority() → 50
// DefaultAdapter::priority() → 0

// In a Laravel app: LaravelAdapter wins
// In a Symfony app: SymfonyAdapter wins
// Neither: DefaultAdapter used as fallback
```

## Boot lifecycle

```php
// 1. Framework boot (happens automatically in service providers)
FrameworkBootstrap::boot();

// 2. Container registration (in Laravel service provider)
ContainerRegistry::set(app());

// 3. Binding resolution (transparent to consumers)
$normalizer = ContainerRegistry::resolve(NormalizerInterface::class);
```

In Laravel, this is handled automatically by the Talred service providers. For other frameworks, call `FrameworkBootstrap::boot()` and `ContainerRegistry::set()` during your application bootstrap.
