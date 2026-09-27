---
title: Zolta Framework Improvement Memo
description: Architecture and developer-experience improvements identified while building a modular Laravel application with Zolta Forge, CQRS, and HTTP.
---

# Zolta Framework Improvement Memo

## Purpose

This memo records improvement opportunities observed while using the Zolta packages in a production-style modular application.

The current architecture is strong because it is predictable and difficult to bypass accidentally:

```text
HTTP attributes
  → validated request
  → input DTO
  → application service
  → command or query
  → handler
  → domain aggregate and repository
  → response DTO and resource
```

The recommendations below do not propose replacing that model. They focus on making the existing architecture easier to understand, safer to evolve, and harder to misuse as the framework and its user base grow.

Although this memo lives in Forge, the work spans:

- `talred/forge`: domain primitives, DTO foundations, errors, architecture contracts
- `zolta/cqrs`: commands, queries, results, events, transactions, generated maps
- `zolta/http`: request pipelines, controller attributes, resources, error rendering, OpenAPI
- shared tooling: diagnostics, static analysis, scaffolding, CI verification, documentation

## Guiding objectives

Every proposed improvement should strengthen at least one of these objectives:

1. Preserve the predictable request-to-domain execution model.
2. Detect contract mistakes before runtime.
3. Make framework behavior inspectable without reading package internals.
4. Keep domain code framework-independent.
5. Make generated metadata reliable and transparent.
6. Clarify transactional and event-delivery guarantees.
7. Provide actionable failures instead of generic runtime errors.
8. Enforce service boundaries as applications grow.

---

## 1. Strengthen pipeline type safety

### Observation

Command and query results are frequently consumed through string-keyed payloads:

```php
['profile' => $profile] = $service
    ->runAndCapture(CreateCandidateProfileCommand::class, [...])
    ->getOrFail();
```

Response resources may also retrieve DTO values by string:

```php
return [
    'main_profile_id' => $this->get('mainProfileId'),
];
```

This is flexible, but a misspelled key or a camel-case/snake-case mismatch is usually discovered only at runtime.

### Risks

- Static analysis cannot prove the result payload shape.
- Refactoring a payload property can silently break downstream consumers.
- Resources can request a key that the DTO does not expose.
- `getOrFail()` communicates success or failure but not the successful value type.

### Proposed solutions

#### Introduce generic result and option annotations

Define package-level generic contracts that PHPStan and Psalm can understand:

```php
/**
 * @template TPayload
 */
final class Result
{
    /** @return TPayload */
    public function getOrFail(): mixed;
}
```

Commands and handlers should declare their payload:

```php
/** @extends Command<CandidateProfilePayload> */
final class CreateCandidateProfileCommand extends Command
{
}
```

#### Prefer typed payload access

Allow consumers to retrieve the payload object rather than destructuring an array:

```php
$payload = $service
    ->run(CreateCandidateProfileCommand::class, [...])
    ->expect(CandidateProfilePayload::class);

$profile = $payload->profile;
```

Keep array conversion for transport and backward compatibility, but make typed access the recommended application API.

#### Add resource contracts

Provide a typed resource base:

```php
/** @extends Resource<CandidateProfilesResponseDTO> */
final class CandidateProfilesResource extends Resource
{
    public function toArray(): array
    {
        return [
            'profiles' => $this->resource->profiles,
        ];
    }
}
```

If dynamic `get()` remains supported, add validation that verifies requested DTO properties during route-map or OpenAPI generation.

#### Supply static-analysis extensions

Create first-party PHPStan rules/extensions for:

- `runAndCapture(Command::class)` result inference
- `getOrFail()` payload inference
- request-to-DTO field compatibility
- DTO-to-resource property compatibility
- command/query handler return compatibility

### Acceptance criteria

- Renaming a payload property produces a static-analysis failure.
- A resource requesting an unknown DTO property fails CI.
- IDEs infer the payload returned by a known command or query.
- Existing array payloads remain usable during migration.

---

## 2. Improve endpoint and pipeline discoverability

### Observation

Declarative controllers are concise and architecturally clean:

```php
#[Route(...)]
#[Request(CreateThingRequest::class, CreateThingDTO::class)]
#[Service(CreateThingService::class, 'Created.', 201)]
#[Response(ThingResource::class)]
final class CreateThingController extends Controller
{
}
```

The trade-off is that developers unfamiliar with Zolta must mentally resolve several attributes to understand the complete runtime path.

### Risks

- Debugging requires knowledge of package internals.
- IDE navigation does not always reveal the full pipeline.
- A valid-looking controller can reference an incompatible request, DTO, service, or response.

### Proposed solutions

#### Add a pipeline inspection command

```bash
php artisan zolta:pipeline candidate-profiles.create
```

Example output:

```text
Route       POST /api/candidate-profiles
Middleware  api, auth:sanctum
Request     CreateCandidateProfileRequest
Input DTO   CreateCandidateProfileDTO
Service     CreateCandidateProfileService
Response    CandidateProfileResource
Status      201
OpenAPI     CandidateProfile
```

Support lookup by route name, controller class, path, command, or application service.

#### Generate a machine-readable pipeline manifest

The route cache should expose normalized metadata that IDE plugins and documentation tools can consume:

```json
{
  "route": "candidate-profiles.create",
  "request": "...CreateCandidateProfileRequest",
  "dto": "...CreateCandidateProfileDTO",
  "service": "...CreateCandidateProfileService",
  "resource": "...CandidateProfileResource"
}
```

#### Add compatibility validation during cache generation

Validate that:

- request fields can construct the declared DTO
- the service accepts the declared DTO
- the resource supports the service response
- route parameters required by the request exist on the route
- documented response status matches the service declaration

### Acceptance criteria

- A developer can inspect any endpoint without opening package source.
- Invalid attribute combinations fail during cache generation.
- Pipeline metadata can be consumed by an IDE or external tool.

---

## 3. Make generated maps self-diagnosing

### Observation

Cached command, query, event, route, and OpenAPI maps provide production performance and deterministic discovery. During development, however, a newly added handler can produce:

```text
No handler registered for App\...\SomeCommand
```

when the source is correct but the generated map is stale.

### Risks

- Developers debug dependency injection or attributes when the real issue is stale metadata.
- Local runtime behavior can differ from CI or production.
- Generated maps can become inconsistent with the current source tree.

### Proposed solutions

#### Add source fingerprints

Each generated map should store:

- framework/package version
- generation timestamp
- configuration hash
- Composer autoload hash
- source-file fingerprint

At runtime in development, compare the stored fingerprint with the current source state.

#### Improve missing-handler diagnostics

Instead of only reporting “No handler registered,” include:

```text
No handler registered for SomeCommand.

A class annotated with #[HandlesCommand(SomeCommand::class)] exists at:
app/Services/.../SomeCommandHandler.php

The command map appears stale.
Run: php artisan zolta:maps:cache
```

#### Add development auto-refresh

Offer an opt-in development mode:

```env
ZOLTA_AUTO_REFRESH_MAPS=true
```

It should rebuild only when fingerprints change, never on every request.

#### Add `zolta:doctor`

```bash
php artisan zolta:doctor
```

It should check:

- stale command/query/event maps
- duplicate handlers
- handlers without messages
- messages without handlers
- wrappers without mapped domain events
- duplicate Laravel listener registration
- route/request/DTO/service/resource compatibility
- missing provider bindings
- unresolved application services

#### Add CI verification mode

```bash
php artisan zolta:maps:verify
```

The command should exit non-zero when generated metadata does not match source.

### Acceptance criteria

- Stale maps are identified explicitly.
- `zolta:doctor` explains the corrective action.
- CI can guarantee that source and maps agree.
- Production retains cached performance without source scanning.

---

## 4. Standardize application error contracts

### Observation

Handlers may currently return a failed `Result`, throw a generic `RuntimeException`, or throw an HTTP-specific exception.

### Risks

- Application and domain layers can become coupled to HTTP semantics.
- Similar failures may render different response shapes.
- Consumers such as CLI commands or queue workers cannot reliably classify failures.
- OpenAPI error documentation can drift from runtime behavior.

### Confirmed failure: HTTP exceptions rendered as server errors

A production workflow exposed a concrete adapter defect:

```text
Controller throws Symfony NotFoundHttpException
  → zolta/http renders HTTP 500 with code server.error
  → Nuxt BFF classifies the Laravel response as an upstream failure
  → user receives HTTP 502 instead of the expected HTTP 404
```

The failure occurred while a document assistant checked a newly generated chat
ID before its first message had been persisted. An absent chat was a valid
initial state, but the `NotFoundHttpException` raised by the show endpoint was
converted into an internal server error.

This behavior can also make ordinary missing-resource cases appear as refresh
or upstream-server failures across jobs, profiles, documents, and chats.

### Proposed solutions

#### Preserve transport exception status codes

The Laravel adapter must recognize `HttpExceptionInterface` before applying its
generic throwable fallback:

```php
if ($exception instanceof HttpExceptionInterface) {
    return ErrorResponse::fromHttpException(
        status: $exception->getStatusCode(),
        message: $exception->getMessage(),
        headers: $exception->getHeaders(),
    );
}
```

At minimum, the adapter must preserve 400, 401, 403, 404, 405, 409, 410, 415,
422, and 429 responses. Unknown exceptions should remain HTTP 500.

Add adapter-level tests proving that:

- `NotFoundHttpException` renders 404, never 500.
- `AccessDeniedHttpException` renders 403.
- `ConflictHttpException` renders 409.
- validation failures render 422.
- exception headers survive rendering where applicable.
- unexpected `RuntimeException` still renders a sanitized 500 response.

Application workflows should still prefer the framework-neutral failures below.
Transport exception support is required for compatibility with Laravel,
Symfony, and intentionally manual controllers.

#### Introduce framework-neutral application failures

Provide explicit failure types:

```php
Result::notFound('candidate_profile', $id);
Result::forbidden('candidate_profile.delete');
Result::conflict('profile_in_use');
Result::validation($violations);
Result::unavailable('document_service');
```

Back them with typed exceptions or immutable failure objects:

```php
ApplicationFailure
├── NotFoundFailure
├── AuthorizationFailure
├── ConflictFailure
├── ValidationFailure
└── DependencyFailure
```

#### Map failures in adapters

`zolta/http` maps application failures to HTTP responses. A console adapter can map the same failure to exit codes and messages.

#### Define a stable error envelope

```json
{
  "error": {
    "code": "candidate_profile.not_found",
    "message": "Candidate profile not found.",
    "details": []
  }
}
```

#### Integrate errors with OpenAPI

Controllers or services should declare expected failures, or the framework should infer them from typed service contracts.

### Acceptance criteria

- Domain and application code do not import Symfony or Laravel HTTP exceptions.
- Equivalent failures have identical API envelopes.
- CLI and queue consumers can classify the same failure.
- Expected errors appear in generated OpenAPI documentation.

---

## 5. Document and enforce transaction/event semantics

### Observation

Zolta provides an effective domain-event pipeline:

```text
Aggregate records event
  → handler releases events
  → mapped dispatcher creates Laravel wrapper
  → listener invokes consumer use case
```

The most important remaining concern is making delivery guarantees explicit.

### Questions the framework must answer

- Are events dispatched before or after the surrounding transaction commits?
- What happens when a synchronous listener fails?
- Can a listener observe uncommitted producer data?
- Are events discarded when a transaction rolls back?
- Does a nested `transactional()` call reuse the current transaction?
- How are queued events serialized?
- What retry and idempotency guarantees exist?

### Proposed solutions

#### Define event delivery modes

Support explicit delivery semantics:

```php
#[DispatchAfterCommit]
final readonly class CandidateProfileDeleted implements EventInterface
{
}
```

Possible modes:

- immediate
- after successful commit
- queued after commit

Use after-commit delivery as the recommended default for cross-service side effects.

#### Add an event outbox option

For applications requiring stronger delivery guarantees, provide an optional transactional outbox adapter:

```text
transaction writes aggregate + outbox event
  → commit
  → worker publishes mapped integration event
  → listener handles idempotently
```

This should remain optional so simple applications retain the current lightweight model.

#### Provide idempotency helpers

Offer a listener middleware or event-consumption record keyed by event ID:

```php
#[Idempotent('candidate-profile-cleanup')]
final class CleanupDeletedCandidateProfileListener
{
}
```

#### Add event identity

Domain events should optionally expose:

- event ID
- aggregate ID
- event type/version
- occurred-at timestamp
- correlation ID
- causation ID

### Acceptance criteria

- Event timing is documented and covered by tests.
- Rollbacks never publish after-commit events.
- Cross-service listeners can be made retry-safe with first-party primitives.
- Applications can opt into an outbox without rewriting domain events.

---

## 6. Enforce architectural boundaries

### Observation

Zolta encourages clean modular architecture, but PHP itself does not prevent:

- Domain code importing Laravel.
- One service importing another service’s infrastructure.
- Controllers bypassing application services.
- Handlers depending directly on Eloquent.
- Cross-service side effects bypassing integration events.

### Proposed solutions

#### Publish first-party PHPStan architecture rules

Example configuration:

```neon
parameters:
  zolta:
    servicesPath: app/Services
    enforce:
      domainFrameworkIndependence: true
      noCrossServiceInfrastructureImports: true
      controllersUseApplicationServices: true
      handlersUseRepositoryContracts: true
```

#### Add an architecture test command

```bash
php artisan zolta:architecture:test
```

Checks should include:

- `Domain` cannot import Laravel, Symfony, Eloquent, or another service
- `Application` cannot import infrastructure models
- `API` cannot access repositories directly
- repository interfaces live in Domain or Application contracts
- repository implementations live in Infrastructure
- cross-service listeners live in the integration boundary
- controllers remain declarative unless explicitly marked as escape hatches

#### Support explicit exceptions

Real applications sometimes need deliberate exceptions. Require a documented attribute or configuration entry rather than silently allowing boundary drift:

```php
#[ArchitectureException(
    reason: 'Temporary migration adapter',
    expires: '2026-12-31'
)]
```

### Acceptance criteria

- Boundary violations fail CI with actionable messages.
- Rules are configurable but strict by default in new Zolta applications.
- Exceptions are explicit, documented, and discoverable.

---

## 7. Improve developer tooling and scaffolding

### Observation

The architecture is predictable once learned, but creating a complete use case involves multiple coordinated files.

### Proposed solutions

#### Add use-case generators

```bash
php artisan zolta:make:write-use-case ProfileService DeleteCandidateProfile
php artisan zolta:make:read-use-case ProfileService ListCandidateProfiles
php artisan zolta:make:event-bridge ProfileService CandidateProfileDeleted EditorService
```

A write-use-case generator can create:

```text
API/Controllers
API/Requests
API/Resources
Application/DTOs/Input
Application/Commands/<UseCase>
Application/Services
tests/Feature
```

#### Generate from package-owned stubs

Stubs should:

- follow current naming conventions
- include strict types
- contain correct Zolta attributes
- include generic/static-analysis annotations
- create a focused test skeleton
- avoid adding infrastructure until requested

#### Provide safe refactoring commands

Examples:

- rename command/query and update maps
- move a use case between service slices
- inspect all consumers of a domain event
- detect unused commands, queries, wrappers, and listeners

### Acceptance criteria

- A complete conventional use case can be scaffolded with one command.
- Generated code passes Pint and static analysis.
- Generated tests demonstrate the intended execution path.

---

## 8. Improve event-map and listener diagnostics

### Observation

Mapped domain events and Laravel listener discovery are powerful but can produce subtle duplication:

- a listener registered through discovery and explicitly
- a wrapper manually dispatched in addition to its domain event
- an event wrapper with no active consumer
- a domain event recorded but never released

### Proposed solutions

Extend `zolta:doctor` and event-map generation to detect:

- duplicate domain-event wrappers
- duplicate Laravel listener registrations
- manually dispatched mapped wrappers
- wrappers with no listeners
- listeners whose dependencies cannot resolve
- recorded events that are never returned by a handler
- released events with no generated mapping

Add:

```bash
php artisan zolta:event:trace CandidateProfileDeleted
```

Example:

```text
Domain event
  App\Services\ProfileService\Domain\Events\CandidateProfileDeleted

Mapped wrapper
  App\Events\CandidateProfileDeleted

Listeners
  App\Listeners\CleanupDeletedCandidateProfileListener (queued)

Consumer services
  DetachCandidateProfileService
  DeleteEditorDocumentService
```

### Acceptance criteria

- Duplicate delivery paths are reported before runtime.
- Every mapped event can be traced from producer to final consumer.
- Container failures are detected during diagnostics.

---

## 9. Clarify framework escape hatches

### Observation

Declarative conventions should remain the default, but frameworks need deliberate escape hatches for unusual endpoints and integrations.

### Proposed solutions

Document supported exceptions such as:

- manual controllers for streaming responses
- file downloads
- webhooks with nonstandard authentication
- long-lived server-sent events
- endpoints requiring custom transaction control
- commands that intentionally return no payload

Introduce an explicit marker:

```php
#[ManualPipeline(reason: 'Streams AI response')]
final class StreamDocumentAgentController extends Controller
{
}
```

Architecture diagnostics can then distinguish intentional manual code from accidental bypasses.

### Acceptance criteria

- Developers know when a manual controller is appropriate.
- Manual pipelines remain visible to diagnostics and OpenAPI.
- Escape hatches do not require disabling architecture rules globally.

---

## 10. Expand testing ergonomics

### Observation

Testing a full Zolta flow may require map generation, container configuration, authentication setup, and payload inspection.

### Proposed solutions

#### Add pipeline test helpers

```php
$this->zolta()
    ->actingAs($user)
    ->dispatch(DeleteCandidateProfileCommand::class, [...])
    ->assertSucceeded()
    ->assertPayloadType(DeleteCandidateProfilePayload::class)
    ->assertDispatched(CandidateProfileDeleted::class);
```

#### Add map assertions

```php
$this->assertCommandHandledBy(
    DeleteCandidateProfileCommand::class,
    DeleteCandidateProfileCommandHandler::class,
);
```

#### Add event-pipeline assertions

```php
$this->assertDomainEventMappedTo(
    DomainCandidateProfileDeleted::class,
    CandidateProfileDeleted::class,
);

$this->assertListenerRegisteredOnce(
    CandidateProfileDeleted::class,
    CleanupDeletedCandidateProfileListener::class,
);
```

#### Provide transaction behavior tests

Package test utilities should make it straightforward to assert:

- event not dispatched on rollback
- event dispatched after commit
- listener queued once
- retry is idempotent

### Acceptance criteria

- Feature packages can test Zolta pipelines without duplicating setup.
- Event and map assertions are first-class.
- Tests clearly distinguish application failure from infrastructure failure.

---

## 11. Create a versioned framework contract

### Observation

Forge, CQRS, and HTTP collaborate closely. Changes to payloads, map formats, attributes, or adapter behavior can affect multiple packages.

### Proposed solutions

Define a versioned internal framework contract covering:

- event-map schema
- command/query-map schema
- route pipeline metadata
- adapter discovery metadata
- result/failure interfaces
- DTO/resource interfaces
- transaction/event hooks

Each package should declare compatible contract versions. Diagnostics should detect incompatible combinations.

### Acceptance criteria

- Package upgrades fail early when framework contracts are incompatible.
- Map formats can evolve with explicit migrations.
- Cross-package compatibility is documented and testable.

---

## Recommended delivery roadmap

### Phase 1 — Diagnostics and documentation

Highest value with low architectural risk:

1. Implement `zolta:doctor`.
2. Improve stale-map and missing-handler errors.
3. Add `zolta:pipeline` and `zolta:event:trace`.
4. Document transaction and event timing precisely.
5. Add CI map verification.

### Phase 2 — Static analysis and typed contracts

1. Add generics to `Result`, `Option`, commands, queries, and payloads.
2. Add PHPStan extensions for handler and payload inference.
3. Add request/DTO/service/resource compatibility checks.
4. Introduce framework-neutral application failures.

### Phase 3 — Architecture enforcement and generators

1. Publish architecture rules.
2. Add use-case and event-bridge generators.
3. Add intentional escape-hatch attributes.
4. Add first-party pipeline testing helpers.

### Phase 4 — Stronger event delivery

1. Add explicit after-commit semantics.
2. Add event identity and correlation metadata.
3. Add idempotent listener support.
4. Provide an optional transactional outbox.

## Success indicators

The framework improvements are successful when:

- A new developer can trace any endpoint or event without reading Zolta internals.
- Stale maps identify themselves instead of resembling missing code.
- Payload and resource mismatches fail static analysis.
- Domain and application layers remain independent of HTTP frameworks.
- Cross-service effects have documented delivery and retry guarantees.
- Architecture drift fails CI.
- Conventional use cases remain concise and predictable.
- Advanced use cases have explicit, visible escape hatches.

## Closing note

Zolta’s central strength is its predictability. The framework already guides applications toward explicit use cases, thin transport layers, protected domain models, and controlled side effects.

The priority should not be to add abstraction for its own sake. The next stage is to make the existing abstractions more observable, more strongly typed, and easier to verify. Tooling should reinforce the architecture while preserving the straightforward execution model that makes Zolta effective.
