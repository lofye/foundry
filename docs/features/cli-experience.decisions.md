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

### Decision: implement CLI autocomplete as emitted shell scripts backed by CLI-owned completion logic
Timestamp: 2026-04-17T09:49:12-04:00

**Context**
- `cli-experience` needed first-class shell autocomplete without weakening deterministic CLI contracts or introducing a second command registry.
- Dynamic completion for execution-spec workflows needed to respect active versus draft lifecycle rules that already govern `implement spec`.

**Decision**
- Add a stable `completion` CLI command that emits bash and zsh completion scripts.
- Derive static command and subcommand candidates from `Foundry\Support\ApiSurfaceRegistry`.
- Complete `implement spec <feature> <id>` dynamically from `docs/specs/`, using active execution-spec ids only by default and excluding drafts.

**Reasoning**
- Emitted scripts keep shell integration explicit, lightweight, and dependency-free.
- Registry-backed static completion keeps help output, CLI verification, and autocomplete aligned with one canonical command surface.
- Active-only spec-id completion preserves execution-spec lifecycle boundaries while still improving CLI ergonomics materially.

**Alternatives Considered**
- Hardcode command lists separately inside shell scripts.
- Include draft execution specs in default completion output.
- Defer autocomplete until a broader shell-integration framework exists.

**Impact**
- Bash and zsh users can enable deterministic Foundry CLI completion immediately.
- CLI help, registry metadata, and surface verification now share the same command source for autocomplete-relevant behavior.
- `implement spec` completion is faster without blurring active and draft execution-spec state.

**Spec Reference**
- Goals
- Constraints
- Expected Behavior
- Acceptance Criteria
