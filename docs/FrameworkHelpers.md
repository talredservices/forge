---
title: Framework Helpers
description: How the strict, framework-neutral `app()` helper works in Talred Forge and when to use it.
---

# Framework Helpers

## Purpose

Talred Forge exposes a single helper—`app()`—that clients in the API or Application layers can use to resolve collaborators without depending on Laravel’s or Symfony’s container APIs. The rest of the code (domain, application) sees only the PSR-11 contract, guaranteeing portability across frameworks.

## Container architecture

1. `Talred\Support\ZoltaForgeContainer` wraps any PSR-11 container and exposes only `get()`/`has()`. Any other method call throws a `RuntimeException`, preventing consumers from calling Laravel-only helpers such as `make`, `bind`, or `instance`.
2. `Talred\Support\ContainerRegistry` stores the strict wrapper and exposes it through a static API. Framework adapters call `ContainerRegistry::set(new ZoltaForgeContainer($frameworkContainer));` during boot.

## The `app()` helper

Defined in `src/helpers.php`, the helper behaves like this:

```php
function app(?string $id = null): mixed
{
    $container = ContainerRegistry::get();

    return $id === null ? $container : $container->get($id);
}
```

The helper:

- Returns the strict container instance when called without arguments.
- Resolves PSR-11 services when passed a fully-qualified class name.
- Throws if no container has been registered by the hosting framework.

### Examples

- Inside a request: `$this->authService = app(AuthenticationServiceInterface::class);`
- Inside an application service: `$mailer = app(MailerInterface::class);`
- When you need the container: `$container = app();` (but keep usage minimal).

## Framework adapters

- **Laravel**: `LaravelBridgeServiceProvider::boot()` registers the helper by calling `ContainerRegistry::set(new ZoltaForgeContainer(app()));`
- **Symfony**: `SymfonyRegistrar::register()` does the same by wrapping the Symfony container before wire-up.

Any new adapter must repeat this pattern to keep the helper portable.

## Use cases

1. **API Requests** – safely resolve collaborators inside `configureDependencies()` instead of overriding constructors.
2. **Application services** – bootstrap optional helpers without adding framework-specific imports.
3. **Console commands and jobs** – share the same abstraction without duplicating container logic.

## Guidelines

- Do **not** call Laravel-only helpers like `make`, `bind`, or `instance`—the helper won’t allow it.
- Avoid leaking `app()` into the Domain layer; keep it in API, Application, or Infrastructure.
- Treat the helper as a PSR-11 port: rely only on `get()`/`has()` and let the framework adapter decide how services are wired.

With this helper, Talred Forge keeps your code portable, testable, and framework-agnostic. Keep it the only entry point whenever you need to resolve a service from the container.
