# Execution Spec: 004-spec-auto-log-on-implementation

## Feature
- execution-spec-system

## Purpose
- Automatically append implementation entries to the implementation log.
- Ensure chronological tracking is never skipped.

## Scope
- Hook into spec execution flow.
- Append entries to `docs/specs/implementation-log.md`.

## Constraints
- Must not duplicate entries.
- Must not log drafts.
- Must use the required format.
- Must be deterministic.

## Requested Changes

### 1. Trigger Point

After successful implementation of an active spec:
- trigger log append

### 2. Log Entry Format

Append:

```md
## YYYY-MM-DD HH:MM:SS ±HHMM
- spec: <feature>/<id>-<slug>.md
- note: <short description>
```

### 3. Timestamp

- must use system time
- must be formatted consistently

### 4. Idempotency

- must not append duplicate entries for the same spec execution

### 5. Safety

- must fail clearly if the log file cannot be written

## Non-Goals
- Do not log draft specs.
- Do not retroactively log past implementations.

## Authority Rule
- Log format must match `docs/specs/README.md` exactly.

## Completion Signals
- The implementation log is updated automatically.
- Entries follow the required format.
- No duplicate entries occur.
- Failures are handled clearly.
- All tests pass.

## Post-Execution Expectations
- The implementation log is always complete and accurate.
- No manual logging is required.
