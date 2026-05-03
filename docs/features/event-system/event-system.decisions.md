### Decision: implement a deterministic synchronous event registry and dispatcher with explicit read surfaces

Timestamp: 2026-05-03T19:35:00-04:00

**Context**

- The active execution spec required a deterministic synchronous event bus with explicit registration and inspection behavior.
- Existing event-related code under `src/Events/*` did not satisfy the spec's required registry/dispatcher contract or CLI/MCP inspection surfaces.

**Decision**

- Implement a dedicated `Foundry\Event\EventRegistry` and `Foundry\Event\EventDispatcher` pair that enforces deterministic registration and synchronous dispatch.
- Add CLI read commands `event:list` and `event:inspect` for deterministic inspection.
- Expose equivalent read behavior through MCP tools `event.list` and `event.inspect` via existing MCP read delegation.

**Reasoning**

- A dedicated registry/dispatcher pair keeps ordering, validation, and failure semantics explicit and testable.
- CLI and MCP read parity preserves one canonical event read model.
- Restricting this iteration to synchronous dispatch keeps behavior deterministic and aligned with current framework guarantees.

**Alternatives Considered**

- Extend the existing `src/Events/*` contracts in place.
- Implement async-capable dispatching in the first iteration.
- Add event inspection only to one surface (CLI or MCP) and defer parity.

**Impact**

- Foundry now has explicit deterministic synchronous event registration and dispatch primitives in `src/Event/*`.
- Event inspection is available consistently through both CLI and MCP read layers.
- The event-system feature context is now grounded in shipped runtime behavior instead of placeholders.

**Spec Reference**

- Goals
- Constraints
- Expected Behavior
- Acceptance Criteria

### Decision: document current event-system scope as the implemented synchronous V1 boundary

Timestamp: 2026-05-03T19:42:00-04:00

**Context**

- The canonical event-system spec defines deterministic synchronous behavior and explicit read surfaces.
- The current implementation intentionally ships only the synchronous V1 boundary and explicitly defers advanced event capabilities.
- Context verification requires an explicit decision entry when state wording could otherwise appear as divergence from broader future-facing expectations.

**Decision**

- Treat the implemented synchronous registry/dispatcher plus CLI and MCP read inspection as the current authoritative state.
- Keep deferred capabilities (async dispatching, wildcard listeners, and richer event semantics) out of current state until promoted by future execution specs.

**Reasoning**

- Explicitly recording the V1 boundary keeps spec intent and present implementation aligned without overstating deferred behavior.
- The decision ledger should preserve why deferred behavior is intentionally excluded from current state.

**Alternatives Considered**

- Expand the implementation immediately to include deferred capabilities.
- Shrink the canonical spec to only the already-implemented subset.

**Impact**

- Feature state can remain truthful about shipped behavior while preserving forward intent.
- Context verification has explicit decision-backed continuity for the current scope boundary.

**Spec Reference**

- Goals
- Non-Goals
- Constraints
- Acceptance Criteria
