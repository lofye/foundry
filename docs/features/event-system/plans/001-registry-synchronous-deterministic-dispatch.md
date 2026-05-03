# Implementation Plan: 001-registry-synchronous-deterministic-dispatch

## Scope
- Implement deterministic synchronous event registry and dispatcher primitives.
- Add deterministic read-only CLI event inspection commands.
- Add MCP read tools for event list/inspect parity.
- Update feature context and decision records.
- Pass strict validation, tests, coverage, and context gates.

## Steps
1. Implement `src/Event/EventRegistry.php` and `src/Event/EventDispatcher.php` with deterministic ordering and validation error handling.
2. Implement CLI `event:list` and `event:inspect` surfaces and wire command registration/help metadata.
3. Extend MCP tool surface with `event.list` and `event.inspect` via CLI read bridge parity.
4. Add unit/integration coverage for registration ordering, dispatch failures, and CLI/MCP deterministic outputs.
5. Update `docs/features/event-system/*` context files and append implementation-log entry.
6. Run strict verification pipeline and repair any context mismatch until fully clean.
