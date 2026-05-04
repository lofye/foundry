# Feature Spec: event-system

## Purpose

Define a deterministic, synchronous event-system contract for explicit inter-feature signaling.

## Goals

- Provide a central event registry with deterministic listener ordering.
- Dispatch listeners synchronously with deterministic failure behavior.
- Expose read-only event inspection through CLI and MCP surfaces.
- Keep registration explicit and reproducible across runs.

## Non-Goals

- No asynchronous dispatching or queue integration.
- No wildcard listener matching or reflection-based discovery.
- No write-capable MCP event operations.

## Constraints

- Event names must be lowercase dot-separated identifiers.
- Listener ordering must be deterministic: higher priority first, equal priority by registration order.
- Event registration must reject invalid names, invalid listeners, and invalid sources deterministically.
- Pack/provider registration must be restricted to boot/registration phase.
- Registration during active dispatch must be rejected outside boot phase.

## Expected Behavior

- `Foundry\Event\EventRegistry` supports deterministic registration and inspection via `register()`, `listenersFor()`, and `all()`.
- `Foundry\Event\EventDispatcher` dispatches synchronously and wraps listener failures in deterministic `FoundryError` metadata.
- CLI commands `event:list` and `event:inspect <event>` return deterministic output and JSON shapes.
- Missing events in `event:inspect` are non-fatal and return empty listener lists.
- MCP tools `event.list` and `event.inspect` expose the same deterministic read model through `mcp:serve`.

## Acceptance Criteria

- Event registry and dispatcher exist with deterministic ordering semantics.
- Forbidden registration behavior is rejected with deterministic error codes.
- CLI event inspection commands are available and deterministic.
- MCP read tools for event inspection are available and read-only.
- Unit and integration coverage validate ordering, failure behavior, and read-surface contracts.

## Assumptions

- Future asynchronous or queued event behavior, if needed, will be introduced by a separate promoted execution spec.
