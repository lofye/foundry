### Decision: initialize canonical context for a planned but unimplemented mcp-server feature

Timestamp: 2026-05-01T12:05:00-04:00

**Context**

- The repository already contained a draft execution spec for `mcp-server`, but it had no canonical feature spec, state, or decisions files.
- Placeholder-only context caused `verify context --json` to report the feature as pass-but-not-consumable.
- The current repository still does not implement an MCP server runtime.

**Decision**

- Create canonical feature context for `mcp-server`.
- Describe the feature truthfully as planned work with draft execution guidance, not as implemented runtime behavior.

**Reasoning**

- Canonical context is required so future MCP work can proceed through normal Foundry feature workflows.
- Truthful state is better than placeholder text because it avoids confusing pass-with-warning outcomes.
- Recording the feature as unimplemented preserves contract honesty without weakening warning semantics globally.

**Alternatives Considered**

- Leave placeholder text in place.
- Change context warning semantics so placeholder-only state would still count as clean.
- Claim planned MCP behavior as if it were already implemented.

**Impact**

- `mcp-server` is now represented by consumable canonical feature context.
- Future MCP implementation can start from aligned docs instead of repairing context first.
- Global context verification can treat this feature as proceed-safe once the state remains aligned.

**Spec Reference**

- Purpose
- Expected Behavior
- Acceptance Criteria

### Decision: keep mcp-server in a draft-planning-only state until active implementation is promoted

Timestamp: 2026-05-01T12:12:00-04:00

**Context**

- `docs/mcp-server/specs/drafts/001-read-layer.md` exists and describes a future deterministic, read-only MCP server surface.
- The repository does not yet implement an `mcp:serve` command or a shipped MCP runtime.
- The feature state needs to say clearly that current work is limited to canonical context plus draft planning artifacts.

**Decision**

- Treat the current `mcp-server` feature state as documentation and planning only.
- Keep the feature unimplemented until an active execution spec is promoted and completed.

**Reasoning**

- This matches the actual repository state and avoids overstating partially planned work as shipped behavior.
- The current-state document can now mention the draft read-layer spec directly without implying runtime support.

**Alternatives Considered**

- Present the draft read-layer plan as if runtime implementation already existed.
- Remove mention of the draft spec from current state entirely.

**Impact**

- The current state may explicitly report that one draft execution spec exists at `docs/mcp-server/specs/drafts/001-read-layer.md`.
- The current state may explicitly report that no implemented `mcp:serve` command or runtime MCP server exists yet.
- Future MCP work remains blocked on promotion to an active execution spec instead of being inferred from draft planning files.

**Spec Reference**

- Expected Behavior
- Acceptance Criteria
- Assumptions
