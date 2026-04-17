### Decision: create cli-experience as a standalone feature
Timestamp: 2026-04-16T15:00:00-04:00

**Context**
- Foundry’s CLI surface has grown into a meaningful subsystem with its own ergonomics, verification requirements, and developer workflows.
- Shell autocomplete and related usability improvements do not fit cleanly under `context-persistence`, `canonical-identifiers`, or `execution-spec-system`.

**Decision**
- Create a standalone feature named `cli-experience`.
- Track CLI usability and discoverability work under this feature, starting with shell autocomplete.

**Reasoning**
- CLI ergonomics are a real product surface and deserve their own canonical context.
- A dedicated feature keeps usability work organized without diluting other feature boundaries.
- This makes future CLI improvements easier to reason about and sequence.

**Alternatives Considered**
- Keep autocomplete under `context-persistence`.
- Fold CLI usability work into `execution-spec-system`.
- Track CLI ergonomics only through ad hoc execution specs without canonical feature context.

**Impact**
- CLI usability improvements now have a dedicated canonical spec, state document, and decision ledger.
- Future CLI-focused specs can be organized coherently under one feature.

**Spec Reference**
- Purpose
- Goals
- Constraints
