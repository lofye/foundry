### Decision: adopt canonical Features workspace with migration-safe compatibility

Timestamp: 2026-05-03T23:15:00-04:00

**Context**

- The repository previously used `docs/features/*` as the only feature-doc workspace.
- The feature-system execution spec requires canonical `Features/` ownership with deterministic migration compatibility.

**Decision**

- Implement canonical `Features/` discovery and CLI inspection/verification surfaces.
- Keep legacy `docs/features/*` readable while preferring canonical paths when both exist.
- Treat `Features/implementation.log` as canonical implementation-ledger location when canonical workspace is present.

**Reasoning**

- Canonical co-location reduces cross-repository lookup cost for humans and agents.
- Compatibility avoids destabilizing existing workflows during incremental migration.
- Deterministic diagnostics make duplication and enforcement drift explicit instead of hidden.

**Alternatives Considered**

- Keep `docs/features/*` as permanent canonical path.
- Hard-cut migration with no compatibility bridge.
- Delay CLI and validation support until a full repository migration.

**Impact**

- Feature workspace inspection and boundary verification now support canonical and legacy layouts.
- Spec validation accepts canonical `Features/*` execution spec and plan paths.
- Implementation-log path semantics now recognize canonical `Features/implementation.log`.

**Spec Reference**

- Goals
- Expected Behavior
- Acceptance Criteria

### Decision: allow migration-phase state details that exceed the current canonical spec wording

Timestamp: 2026-05-03T23:28:00-04:00

**Context**

- The Current State documents concrete implementation details (diagnostic codes, canonical/legacy compatibility behavior, and migration notes).
- Some of those details are more specific than the current spec text and can appear as state-spec divergence during alignment checks.

**Decision**

- Keep the Current State details as implemented behavior and treat them as an explicit migration-phase refinement.
- Use this decision entry as the supporting reference for state claims that are more specific than the current spec wording.

**Reasoning**

- The state document should remain truthful about what is actually implemented.
- Recording the divergence explicitly is safer than deleting implemented details or over-broadening the spec contract prematurely.

**Alternatives Considered**

- Remove specific state claims so alignment appears strict but less informative.
- Expand the spec immediately with every implementation detail.

**Impact**

- Alignment tooling can treat the documented divergence as intentional and decision-backed.
- Current State retains implementation-useful detail during incremental migration.

**Spec Reference**

- Constraints
- Expected Behavior
- Assumptions

### Decision: document migration-phase state claims as explicit divergence references

Timestamp: 2026-05-03T23:35:00-04:00

**Context**

- Current State includes migration-phase implementation claims that can be more specific than existing spec phrasing.

**Decision**

- Deterministic `feature:list`, `feature:inspect <feature>`, `feature:map`, and `verify features` CLI surfaces are available.
- `Features/implementation.log` is used when canonical workspace is present.
- `spec:validate` supports canonical `Features/*/specs` and `Features/*/plans`.
- Legacy `docs/features/*` inputs remain readable during migration.
- Canonical and legacy duplicate definitions produce deterministic diagnostics.
- Boundary enforcement defaults to enabled and disabled mode emits visible warnings.

**Reasoning**

- Keeping explicit migration-phase details in both state and decisions preserves truthful implementation context while allowing intentional divergence tracking.

**Alternatives Considered**

- Remove migration-phase implementation details from Current State.
- Delay canonical workspace reporting until a full physical migration is complete.

**Impact**

- Decision ledger now explicitly references the implemented migration-phase state claims.
- Alignment tooling can treat these claims as decision-backed during incremental migration.

**Spec Reference**

- Goals
- Expected Behavior
- Acceptance Criteria
