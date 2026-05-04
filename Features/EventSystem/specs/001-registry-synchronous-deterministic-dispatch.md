# Execution Spec: 001-registry-synchronous-deterministic-dispatch

## Purpose

Introduce a deterministic, synchronous Event Bus for inter-feature communication.

This provides a single, explicit mechanism for features to emit and react to events without direct coupling, while preserving Foundry’s guarantees:

- deterministic execution
- no hidden behavior
- inspectable system state
- testable outcomes

## Core Principle

Events are:

- explicitly defined
- explicitly registered
- synchronously dispatched
- deterministically ordered

No implicit listeners. No async behavior. No side effects outside defined boundaries.

## Goals

1. Introduce a central EventRegistry.
2. Allow features and packs to register event listeners through explicit registration surfaces.
3. Support synchronous dispatch only.
4. Guarantee deterministic listener execution order.
5. Make all events and listeners inspectable.
6. Prevent hidden or dynamic listener registration at runtime.
7. Ensure full testability and reproducibility.

## Non-Goals

- No asynchronous events.
- No queueing.
- No wildcard listeners.
- No runtime discovery through reflection.
- No implicit global state mutation.
- No event bubbling.
- No propagation control.
- No listener auto-discovery.

## Definitions

### Event

A named signal emitted by the system.

An event has:

- a stable string identifier, such as `feature.created` or `pack.installed`
- a JSON-serializable associative-array payload

### Listener

A callable registered to a specific event.

A listener has:

- an explicit event name
- an explicit source
- an explicit priority
- deterministic registration order

### Dispatch

The synchronous invocation of all listeners registered for an event.

Dispatch is complete only after every matching listener has completed successfully, or after a listener failure has produced a deterministic error.

## Required Runtime Components

### EventRegistry

Add:

`src/Event/EventRegistry.php`

Responsibilities:

- register listeners
- store listeners by event name
- preserve registration order
- enforce deterministic priority ordering
- expose inspectable event/listener state

Required API:

```php
register(string $event, callable $listener, int $priority = 0, ?string $source = null): void
listenersFor(string $event): array
all(): array
```

Ordering rules:

1. Higher priority executes first.
2. Equal priority preserves registration order.
3. Output order must be stable across repeated runs.

Validation rules:

- event names must be non-empty strings
- event names must use lowercase dot-separated identifiers
- listeners must be callable
- priority must be an integer
- source must be stable when present

Invalid registration must produce deterministic errors.

Required error codes:

- `EVENT_INVALID_NAME`
- `EVENT_LISTENER_INVALID`
- `EVENT_SOURCE_INVALID`

### EventDispatcher

Add:

`src/Event/EventDispatcher.php`

Responsibilities:

- dispatch events synchronously
- invoke listeners in registry-defined order
- preserve deterministic failure behavior
- avoid hidden mutation of registry state during dispatch

Required API:

```php
dispatch(string $event, array $payload = []): void
```

Behavior:

- fetch listeners from `EventRegistry`
- execute listeners synchronously
- pass payload to each listener
- do not swallow listener exceptions
- wrap listener failures in deterministic `FoundryError`
- reject listener registration during active dispatch unless registration is part of an explicit boot/registration phase

Required error codes:

- `EVENT_DISPATCH_FAILED`
- `EVENT_REGISTER_DURING_DISPATCH`

## Pack Integration

Pack service providers may register event listeners during provider registration.

Example intended surface:

```php
public function register(PackContext $context): void
{
    $context->events()->listen(
        'feature.created',
        fn(array $payload) => null,
        priority: 0
    );
}
```

The actual implementation may use the existing `PackContext` style, but it must provide a clear and explicit event-listener registration surface.

Constraints:

- event listener registration is allowed only during pack registration/bootstrapping
- listener registration must be deterministic
- listener source must identify the registering pack/provider when available
- registration must not perform filesystem writes
- registration must not mutate global runtime state

Violations must produce deterministic errors.

Required error codes:

- `EVENT_REGISTER_OUTSIDE_BOOT`
- `EVENT_REGISTER_SIDE_EFFECT`

## CLI Surface

Add read-only event inspection commands:

```bash
php bin/foundry event:list
php bin/foundry event:list --json
php bin/foundry event:inspect <event>
php bin/foundry event:inspect <event> --json
```

### `event:list`

Plain text output must include:

- event name
- listener count

JSON output must include:

```json
{
  "events": [
    {
      "name": "feature.created",
      "listener_count": 2
    }
  ]
}
```

### `event:inspect <event>`

Plain text output must include:

- event name
- listeners
- listener priority
- listener source when available

JSON output must include:

```json
{
  "event": "feature.created",
  "listeners": [
    {
      "priority": 10,
      "source": "example-pack",
      "order": 1
    }
  ]
}
```

Output ordering must be deterministic.

Missing events must return an empty listener list, not a fatal error.

## MCP Read Compatibility

Expose event registry state through the MCP read layer.

Add read-only MCP tools:

- `event.list`
- `event.inspect`

No MCP write operations are allowed.

MCP output must use the same deterministic ordering and equivalent data shape as the CLI JSON output.

## Determinism Requirements

The event system must guarantee:

- identical listener ordering across runs
- no implicit listener discovery
- no reflection-based auto-registration
- no random ordering
- no timestamp-dependent output
- no environment-dependent listener ordering
- stable JSON key ordering where existing project conventions require it

## Testing Requirements

### Unit Tests

Add coverage for:

- event registration
- invalid event names
- invalid listeners
- priority ordering
- stable equal-priority ordering
- listener source preservation
- `listenersFor()` deterministic output
- `all()` deterministic output

### Integration Tests

Add coverage for:

- dispatch invokes listeners in deterministic order
- listener failure produces deterministic `FoundryError`
- pack provider can register listeners
- registration outside allowed boot/registration phase fails
- `event:list --json` output is deterministic
- `event:inspect <event> --json` output is deterministic
- MCP `event.list` and `event.inspect` are read-only and deterministic

### Regression Tests

Add coverage ensuring:

- dispatch does not reorder listeners between runs
- equal priority listeners preserve registration order
- missing event inspection is stable and non-fatal

## Documentation / Context Updates

Update the event-system feature context files as appropriate:

- `docs/features/event-system/event-system.spec.md`
- `docs/features/event-system/event-system.md`
- `docs/features/event-system/event-system.decisions.md`

Append the required implementation entry to:

- `docs/features/implementation-log.md`

If the event-system context files do not yet exist, create them using the current Foundry feature-context conventions.

## Acceptance Criteria

Implementation is complete only when:

- `EventRegistry` exists and enforces deterministic registration behavior
- `EventDispatcher` exists and dispatches synchronously
- listener priority ordering is deterministic
- equal-priority listener order is stable
- pack/provider listener registration is supported
- forbidden registration behavior is rejected deterministically
- `event:list` and `event:inspect` exist
- JSON output is deterministic
- MCP read tools expose event registry state
- tests cover unit, integration, CLI, and MCP behavior
- `php bin/foundry spec:validate --json` passes
- `php vendor/bin/phpunit` passes
- `php -d xdebug.mode=coverage vendor/bin/phpunit --coverage-text` exits 0
- `php bin/foundry context check-alignment --feature=event-system --json` passes
- `php bin/foundry verify context --json` passes

## Notes

This spec intentionally creates the smallest useful Event Bus foundation.

It does not introduce asynchronous events, queueing, wildcard listeners, listener discovery, or complex event schemas.

Those should be introduced only by future specs if they remain compatible with Foundry’s deterministic, inspectable, compiler-like architecture.
