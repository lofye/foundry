# Execution Spec: 003-plan-persistence-and-history

## Feature
- generate-engine

## Purpose
- Persist every completed or aborted generate run as a first-class artifact.
- Make generation history inspectable, auditable, and reusable by later commands.
- Establish the storage contract required for replay, undo, collaboration, and metrics work.

## Scope
- Persist generate runs under a deterministic repository-local storage path.
- Store enough canonical data to inspect what was planned, what was approved, what executed, and how verification ended.
- Add CLI commands for listing and showing persisted plans.
- Integrate persistence with ordinary generate and interactive generate flows.
- Keep this spec focused on persistence and inspection only.

## Constraints
- Storage must be local to the repository.
- Persistence must be deterministic and machine-readable.
- Stored plans must be append-only artifacts; later commands may read them but must not rewrite history in place.
- Do not require an external database or service.
- Do not implement replay execution in this spec.
- Do not implement undo in this spec.
- Do not silently persist partial malformed records.
- Reuse existing generate-engine structures where practical.

## Inputs

Expect inputs such as:
- `GenerationContextPacket`
- `GenerationPlan`
- interactive review result data when interactive mode is used
- execution result data
- verification result data

If any critical input is missing:
- fail clearly and deterministically
- do not invent missing fields
- do not write a partial record that pretends execution succeeded

## Requested Changes

### 1. Add Repository-Local Plan Storage

Persist plans under:

```text
.foundry/plans/
```

Each persisted artifact must use a deterministic filename shape that includes:
- timestamp
- plan id

Preferred filename shape:

```text
.foundry/plans/<timestamp>_<plan-id>.json
```

Timestamp formatting must be stable and filesystem-safe.

### 2. Define the Persisted Plan Record

Each persisted plan record must include, at minimum:

- `plan_id`
- `timestamp`
- `intent`
- `mode`
- `targets`
- `generation_context_packet`
- `plan_original`
- `plan_final` (when interactively modified; otherwise same as original or null by explicit contract)
- `interactive` metadata when interactive mode is used
- `user_decisions` when interactive mode is used
- `actions_executed`
- `affected_files`
- `risk_level`
- `verification_results`
- `status` (`success|failed|aborted`)
- `metadata`
  - framework version when available
  - schema/storage version
  - other deterministic version markers already available

Do not store lossy summaries in place of canonical plan/execution data when the canonical data already exists.

### 3. Add a Storage Schema Version

Include an explicit persisted-record schema/storage version field from the beginning.

This is mandatory in V1 so later replay/undo/history evolution has a stable compatibility anchor.

### 4. Integrate With Generate Flows

Persist a plan record whenever generate reaches a terminal outcome, including:

- success
- failed
- aborted/rejected interactive sessions

Interactive generate must persist:
- original plan
- final modified plan
- user decisions
- approval/rejection result

Non-interactive generate must still persist a canonical record.

### 5. Add `plan:list`

Add:

```bash
foundry plan:list
```

This command must provide a deterministic listing of persisted plans.

Human output should show, at minimum:
- plan id
- timestamp
- intent
- mode
- status

If JSON output is supported, it must include a stable machine-readable list of plan summaries.

### 6. Add `plan:show <plan_id>`

Add:

```bash
foundry plan:show <plan_id>
```

This command must show the persisted record for one plan.

Human output should support:
- summary
- full stored plan view
- executed actions
- verification result view

If JSON output is used, it should return the canonical persisted record or a stable machine-readable projection of it.

### 7. Plan Identity

Each persisted plan must have:
- UUID plan id
- timestamp
- stable storage path
- optional integrity hash if already straightforward to compute safely

Plan id is the primary lookup key.

### 8. Keep Persistence Deterministic

Given the same execution outcome, the stored record shape must be stable.

This spec does not require deterministic timestamps across different runs, but it does require:
- stable field names
- stable ordering where the repository already treats ordering as canonical
- stable status semantics
- stable command output

### 9. Tests

Add focused coverage proving:

- plans persist after successful generate
- plans persist after aborted interactive generate
- persisted records contain required canonical fields
- `plan:list` works deterministically
- `plan:show` resolves by plan id deterministically
- storage schema version exists
- interactive original/final plan data is preserved correctly
- existing generate behavior still works

## Non-Goals
- Do not implement replay execution in this spec.
- Do not implement undo in this spec.
- Do not add external storage.
- Do not introduce branching plan history.
- Do not add team approval workflows in this spec.
- Do not replace git history.

## Canonical Context
- Canonical feature spec: `docs/features/generate-engine.spec.md`
- Canonical feature state: `docs/features/generate-engine.md`
- Canonical decision ledger: `docs/features/generate-engine.decisions.md`

## Authority Rule
- Every generate run must become a first-class persisted artifact.
- Persisted plan records must be complete enough to support later inspection, replay, and undo work.
- History must be append-only and deterministic.

## Completion Signals
- Generate runs are persisted under `.foundry/plans/`
- `plan:list` exists
- `plan:show` exists
- persisted records include schema/storage version and canonical plan/execution metadata
- interactive runs preserve both original and modified plan views
- all tests pass

## Post-Execution Expectations
- Foundry now remembers generate runs as canonical artifacts.
- Developers can inspect past generate work without relying on logs or memory.
- Later replay, undo, collaboration, and metrics work has a stable foundation.
