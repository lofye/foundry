# Execution Spec: 009-enforce-sequential-execution-spec-ids

## Feature

- execution-spec-system

## Purpose

Enforce strict numeric continuity for execution spec IDs so a feature cannot implement, validate, or log a later spec while an earlier numeric slot is missing.

This closes the gap that allowed `009` to be implemented before `008` or `008.NNN` existed. From this point forward, execution spec IDs are ordered contracts, not conceptual labels.

## Rule

Execution spec IDs must be contiguous within each feature.

Skipping numeric IDs is invalid.

Examples:

```text
001.md
002.md
003.md
```

is valid.

```text
001.md
003.md
```

is invalid because `002` is missing.

```text
007.md
007.001.md
007.002.md
009.md
```

is invalid if `008.md` or the required `008.*` sequence is missing according to the feature's active ID shape.

## Canonical Paths

Validate execution specs under:

```text
docs/features/<feature>/specs/*.md
docs/features/<feature>/specs/drafts/*.md
```

Validate plans under:

```text
docs/features/<feature>/plans/*.md
```

The implementation log remains:

```text
docs/features/implementation-log.md
```

## Required Behavior

1. `spec:validate` must detect skipped execution spec IDs within each feature.
2. Skipped IDs must fail validation deterministically.
3. The error must identify:
   - feature
   - missing ID
   - next observed ID
   - path of the file that caused the gap to become visible
4. The validator must check both active specs and draft specs.
5. A later draft may not exist if an earlier numeric ID is missing.
6. A later active spec may not exist if an earlier numeric ID is missing.
7. Implementation-log validation must reject log entries for skipped specs.
8. Any command that creates a spec ID must allocate the next contiguous ID only.
9. Any command that promotes, implements, plans, or logs a spec must refuse to proceed if the feature has skipped IDs.
10. The user-facing error must clearly state that skipping numbers violates execution-spec-system rules.

## ID Continuity Model

Use deterministic numeric ordering by padded dot-separated integer segments.

Examples of ordering:

```text
007
007.001
007.002
007.003
008
008.001
009
```

Rules:

1. Top-level IDs within a feature must be contiguous:

```text
001, 002, 003, ...
```

2. Child IDs under the same parent must be contiguous:

```text
007.001, 007.002, 007.003, ...
```

3. A child sequence may exist only if its parent exists.

For example:

```text
007.001
```

requires:

```text
007
```

4. The first child segment under a parent must be `001`.

5. Gaps are invalid at every hierarchy level.

For example:

```text
007.001
007.003
```

is invalid because `007.002` is missing.

6. Padding is still required. Non-padded IDs remain invalid.

## Historical Exception

The repository had one pre-rule cleanup where execution specs were renumbered before this continuity contract existed.

Do not build permanent migration exceptions into the validator.

Do not add fallback behavior.

After this spec is implemented, future renumbering, skipping, or backfilling by rewrite is forbidden.

## Required Validator Changes

Update execution spec validation so it performs continuity checks after filename structure checks succeed.

The check must ignore files that are already structurally invalid for other reasons when calculating continuity, but the validator must still report those structural errors normally.

The validator must not produce noisy duplicate gap reports. Report the smallest deterministic set of missing IDs needed to explain the invalid sequence.

Recommended error shape for JSON output:

```json
{
  "type": "execution_spec_id_gap",
  "feature": "execution-spec-system",
  "missing_id": "008",
  "next_observed_id": "009",
  "path": "docs/features/execution-spec-system/specs/009-example.md",
  "message": "Execution spec IDs must be contiguous. Missing 008 before 009. Skipping numbers violates execution-spec-system rules."
}
```

Plain-text output must be deterministic and include the same facts.

## Required Command Behavior

Update affected commands and services as needed, including but not limited to:

- `spec:new`
- `spec:validate`
- `spec:plan` if present
- spec promotion/implementation helpers if present
- implementation-log validation
- completion/help output if it describes ID allocation

Commands that create IDs must never choose a number that skips over an available earlier slot.

Commands that operate on existing specs must fail before proceeding when the feature contains skipped IDs.

## Agent Guidance Updates

Update `AGENTS.md`, `APP-AGENTS.md`, `README.md`, `APP-README.md`, and `docs/features/README.md` where relevant to state:

- Execution spec IDs are ordered contracts.
- IDs must be contiguous within each feature.
- Skipping numbers is forbidden.
- Agents must stop instead of implementing, promoting, planning, or logging a spec when a numeric gap exists.
- Renumbering existing specs is forbidden after this rule is in place.

## Tests

Add or update tests proving:

1. `001`, `002`, `003` passes.
2. `001`, `003` fails with missing `002`.
3. `007`, `007.001`, `007.002`, `008` passes.
4. `007`, `007.001`, `007.003` fails with missing `007.002`.
5. `007.001` without `007` fails.
6. Draft specs participate in continuity validation.
7. Active specs participate in continuity validation.
8. Mixed active/draft specs are checked together per feature.
9. Implementation-log entries for skipped IDs fail validation.
10. `spec:new` allocates the next contiguous ID.
11. Plain-text and JSON validation outputs are deterministic.
12. Invalid filename structures do not cause duplicate or misleading continuity errors.

## Acceptance Criteria

- `spec:validate --json` fails when any feature has skipped execution spec IDs.
- `spec:validate` reports deterministic gap errors.
- Spec creation/allocation uses the next contiguous ID.
- Spec promotion/planning/implementation/logging refuses to proceed when skipped IDs exist.
- Agent-facing docs state the continuity rule explicitly.
- Tests cover top-level gaps, child gaps, missing parents, drafts, active specs, and implementation-log behavior.
- No fallback or migration exception weakens the rule.

## Verification

Run and pass:

```bash
php bin/foundry spec:validate --json
php bin/foundry verify context --json
php bin/foundry context check-alignment --feature=execution-spec-system --json
php bin/foundry verify contracts --json
php bin/foundry verify graph --json
php bin/foundry verify pipeline --json
php vendor/bin/phpunit
php -d xdebug.mode=coverage vendor/bin/phpunit --coverage-text
```

## Implementation Log

After all checks pass, append the required implementation entry to:

```text
docs/features/implementation-log.md
```
