# Talred Forge

**The missing domain layer for PHP.**

Stop scattering validation across controllers, form requests, and service classes. Forge gives you true Domain-Driven Design primitives — Value Objects, Entities, Aggregates, Rules, Specifications, Policies, Invariants, and Transformers — all driven by PHP 8 attributes, all enforced at construction time, all framework-agnostic.

```php
$email = Email::resolve(['address' => '  John@Example.com  ']);
// Trimmed → lowercased → format-validated → domain-ready. One line.
```

---

## Why Forge?

### The problem

PHP's ecosystem has excellent tools for persistence (Eloquent, Doctrine) and HTTP (Laravel, Symfony), but the **domain layer** — the part that enforces your business rules — is left as a DIY exercise. The result: validation logic scattered across controllers, form requests, middleware, and service classes. Rules are duplicated. Invariants are violated. Bugs hide in the gaps.

### What Forge does differently

| Approach | How it works | Trade-off |
|----------|-------------|-----------|
| Manual VOs | Hand-written constructors with inline validation | No reuse, no composition, no discovery |
| Spatie Laravel Data | Data transfer objects with casting | Persistence-layer focused, no domain rules/specs/invariants |
| Doctrine Embeddables | ORM-coupled value types | Tied to Doctrine lifecycle, no standalone resolution |
| **Talred Forge** | **Attribute-driven pipeline: Transform → Validate → Specify → Construct → Enforce** | **Zero inheritance tax, composable, framework-agnostic** |

Forge treats Value Object construction as a **pipeline**, not a constructor:

1. **`#[Transform]`** — Normalize raw input (trim, lowercase, parse dates)
2. **`#[UseRule]`** — Guard property constraints (non-empty, max length, regex, password strength)
3. **`#[UseSpecification]`** — Evaluate business logic (email format, allowed domain, minimum age)
4. **Nested VO resolution** — Recursively construct child Value Objects from raw arrays
5. **`#[UseInvariant]`** — Enforce class-level structural guarantees post-construction
6. **`#[UsePolicy]`** — Apply post-construction behavior (token generation, hash verification)

Every stage is **declarative** (PHP 8 attributes), **composable** (`.and()`, `.or()`, `.not()`), and **cacheable** (sub-millisecond on warm calls).

### Who is this for?

- Teams building **domain-rich applications** who want rules enforced at the model, not the controller
- Projects that need **shared domain logic** across multiple entry points (API, CLI, queue workers, events)
- Developers who want DDD without the ceremonial overhead of Java/C#-style frameworks

## Installation

```bash
composer require talred/forge
```

Laravel adapter discovery is automatic through Composer metadata.

### Namespace compatibility

`talred/forge` exposes the public `Talred\...` namespaces while keeping the
existing `Zolta\...` implementation and imports intact. New applications and
examples should use `Talred\...`; existing applications can continue using
`Zolta\...` during the staged migration. The compatibility aliases are
additive and do not duplicate or rewrite the internal implementation.

---

## What's in the box

### Value Objects with auto-resolution

The centerpiece. Declare properties with attributes — Forge handles the rest:

```php
use Talred\Domain\Attributes\Transform;
use Talred\Domain\Attributes\UseRule;
use Talred\Domain\Attributes\UseSpecification;
use Talred\Domain\Rules\NonEmptyRule;
use Talred\Domain\Transformers\EmailNormalizer;
use Talred\Domain\Specifications\EmailFormatSpecification;
use Talred\Domain\ValueObjects\ValueObject;

final class Email extends ValueObject
{
    public function __construct(
        #[Transform(EmailNormalizer::class, ['lowercase' => true])]
        #[UseRule(NonEmptyRule::class)]
        #[UseSpecification(EmailFormatSpecification::class)]
        public readonly string $address,
    ) {}
}

$email = Email::resolve(['address' => '  John@Example.com  ']);
// Pipeline: trim → lowercase → assert non-empty → validate format
// Result: $email->address === "john@example.com"
```

Nested VOs resolve recursively — a `UserCredential` containing an `Email` and `Password` resolves the entire tree from a flat array:

```php
$credential = UserCredential::resolve([
    'email' => ['address' => 'john@example.com'],
    'password' => ['value' => 'S3cure!Pass'],
]);
```

### 8 built-in rules

`NonEmptyRule` · `MaxLengthRule` · `RegexRule` · `UuidRule` · `PasswordRule` · `PasswordPolicyRule` · `PermissionNameRule` · `PositiveNumberRule`

All composable: `$rule->and(new MaxLengthRule(255))->or(new FallbackRule())`.

### 5 built-in specifications

`EmailFormatSpecification` · `AllowedDomainSpecification` · `UuidSpecification` · `MinAgeSpecification` + abstract base for your own.

Compose with logical operators: `$spec->and($other)`, `$spec->or($fallback)`, `$spec->not()`.

### 3 built-in transformers

`EmailNormalizer` · `DateTimeNormalizer` · `IdentifierNormalizer` — pipe them: `$t->and(new OtherTransformer())`.

### Invariants & Policies

**Invariants** enforce class-level structural guarantees after construction:

```php
#[UseInvariant(CreditInvariant::class)]
final class Credit extends ValueObject { /* ... */ }
```

**Policies** apply post-construction behavior — token generation, hash verification, expiry checks:

```php
#[UsePolicy(AccessTokenPolicy::class)]
final class AccessToken extends ValueObject { /* ... */ }
```

Built-in: `EmailVOInvariant` · `RoleNameInvariant` · `VerifiedAtInvariant` · `CreditInvariant` · `PasswordPolicy` · `EmailPolicy` · `AccessTokenPolicy`.

### Entities & Aggregates

Event-recording domain objects with immutability protection:

```php
class User extends AggregateRoot
{
    // Magic read access, immutable writes, domain event recording
    public static function create(...): self
    {
        $user = new self(...);
        $user->recordThat(new UserCreatedEvent($user->id));
        return $user;
    }
}
```

### Framework-agnostic adapter system

Forge auto-discovers framework adapters from Composer metadata — no manual registration, no framework coupling:

```php
FrameworkBootstrap::boot(); // discovers Laravel, Symfony, or custom adapters
ContainerRegistry::set(app()); // PSR-11 compatible
$logger = ContainerRegistry::resolve(LoggerInterface::class);
```

Consumer packages (`talred/cqrs`, `talred/http`) use this same mechanism to stay decoupled.

---

## Performance

Benchmarked on a real application (Laravel 12, PHP 8.3, SQLite):

| Operation | Cold (first call) | Warm (cached) |
|-----------|-------------------|---------------|
| Single VO hydration | 2–5ms | **< 0.6ms** |
| Complex command (3 nested VOs) | 9ms | **< 1ms** |
| Reflection metadata resolution | 4ms | **cached — 0ms** |

Forge caches ReflectionClass instances, property metadata, attribute data, and type resolution maps per class. After the first construction, subsequent VO creation is effectively free.

---

## 25+ built-in Value Objects

Production-ready domain types for common use cases:

**Identity:** `UserId` · `RoleId` · `PermissionId` · `OAuthAccountId` · `AbstractUuid`  
**Auth:** `Email` · `Password` · `AccessToken` · `RefreshToken` · `VerificationCode` · `UserCredential`  
**OAuth:** `OAuthProvider` (Google, Microsoft, GitHub) · `OAuthProviderId`  
**Profile:** `Username` · `RoleName` · `PermissionName` · `AvatarUrl`  
**Domain:** `Credit` · `Description` · `Pagination` · `Terms`

## Tests

```bash
composer run qa          # Full suite: lint + analyse + phpmd + rector + test
composer run test        # PHPUnit only
```

Monorepo runner:

```bash
./scripts/run-package-tests.sh packages/forge qa
```

**58 tests, 88 assertions** covering VO resolution, rule composition, specification logic, invariant enforcement, entity events, adapter discovery, and Talred namespace compatibility.

---

## Collaboration

1. Keep Forge focused on domain concerns only.
2. Put integration/runtime concerns in adapters or consumer packages.
3. Run `composer run qa` before opening a PR.

---

## Part of the Talred Ecosystem

Forge is the **foundation layer** — consumed by the application and transport layers:

```
┌─────────────────────────────────────────────┐
│  talred/http (Transport)                     │
│  Attribute-driven routing & response        │
├─────────────────────────────────────────────┤
│  talred/cqrs (Application)                   │
│  Commands, queries, events, transactions    │
├─────────────────────────────────────────────┤
│  talred/forge (Domain) ← you are here        │
│  Value Objects, rules, specs, entities      │
└─────────────────────────────────────────────┘
```

- **CQRS** hydrates commands and queries using Forge's VO resolution pipeline
- **HTTP** maps validated request data into domain objects through Forge
- Domain constraints are enforced **once**, at the model — not duplicated across controllers, handlers, or jobs

| Package | Layer | Link |
|---------|-------|------|
| **talred/forge** | **Domain** | You are here |
| talred/cqrs | Application | [`packages/cqrs`](../cqrs) |
| talred/http | Transport | [`packages/http`](../http) |

---

## Documentation

Full documentation is available in the [`docs/`](./docs/) directory.

## License

[MIT](LICENSE) © 2026 Redouane Taleb
