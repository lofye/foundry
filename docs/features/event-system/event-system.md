# Feature: event-system

## Purpose

- Record the current repository state for Foundry's deterministic synchronous event system.

## Current State

- `Foundry\Event\EventRegistry` supports deterministic registration and inspection via `register()`, `listenersFor()`, and `all()`.
- `Foundry\Event\EventDispatcher` dispatches synchronously and wraps listener failures in deterministic `FoundryError` metadata.
- Event registry and dispatcher exist with deterministic ordering semantics.
- Forbidden registration behavior is rejected with deterministic error codes.
- CLI commands `event:list` and `event:inspect <event>` return deterministic output and JSON shapes.
- Missing events in `event:inspect` are non-fatal and return empty listener lists.
- MCP tools `event.list` and `event.inspect` expose the same deterministic read model through `mcp:serve`.
- MCP read tools for event inspection are available and read-only.
- Unit and integration coverage validates ordering, failure behavior, and read-surface contracts.

## Open Questions

- Whether future event-system evolution should include asynchronous dispatching remains unresolved.
- Whether wildcard or pattern listener support is needed remains unresolved.

## Next Steps

- Keep event-system read surfaces aligned across CLI and MCP as future event features are added.
- Introduce advanced event capabilities only through promoted execution specs that preserve determinism.
