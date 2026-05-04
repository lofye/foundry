# Execution Spec: 004-spec-auto-log-on-implementation

## Feature
- execution-spec-system

## Purpose
- Automatically append implementation entries to the implementation log when an active execution spec is implemented successfully.
- Ensure project-wide execution-spec chronology is captured consistently and never depends on manual follow-through.
- Keep implementation logging deterministic, safe, and aligned with the required log format in `docs/features/README.md`.

## Scope
- Hook into the active execution-spec implementation flow.
- Append entries to `docs/features/implementation-log.md`.
- Enforce required log-entry formatting.
- Prevent duplicate entries for the same completed implementation event.
- Keep this focused on automatic logging only.

## Constraints
- Must not log draft specs.
- Must not duplicate entries for the same implementation event.
- Must use the required format from `docs/features/README.md`.
- Must be deterministic in structure and behavior.
- Must surface log-write failures clearly and deterministically.
- Must not silently skip a required log append.
- Must integrate with the current implementation flow without introducing ambiguous partial-success behavior.

## Requested Changes

### 1. Trigger Point

After successful implementation of an active execution spec, Foundry must automatically append an implementation entry to:

`docs/features/implementation-log.md`

This must occur only after implementation has succeeded.

Do not append log entries:
- before implementation succeeds
- for draft specs
- for failed or partial implementations

### 2. Eligible Spec Scope

Only active specs are eligible.

Eligible path shape:

```text
docs/features/<feature>/specs/<id>-<slug>.md
```

Ineligible path shape:

```text
docs/features/<feature>/specs/drafts/<id>-<slug>.md
```

If the target spec is a draft, no log entry may be written.

### 3. Required Log Entry Format

Append entries using the required format defined in `docs/features/README.md`.

Required base format:

```md
## YYYY-MM-DD HH:MM:SS ±HHMM
- spec: <feature>/<id>-<slug>.md
```

Optional extended format:

```md
## YYYY-MM-DD HH:MM:SS ±HHMM
- spec: <feature>/<id>-<slug>.md
- note: <short implementation note>
```

The implementation must preserve this exact structural shape.

### 4. Timestamp Rules

Timestamp behavior must be deterministic in format.

Requirements:
- use system time
- format timestamps exactly as `YYYY-MM-DD HH:MM:SS ±HHMM`
- preserve append-only chronological ordering in the log

### 5. Duplicate Prevention

The auto-log behavior must not create duplicate entries for the same completed spec implementation.

At minimum:
- repeated logging of the same completed implementation event must be prevented
- accidental double-append within one implementation flow must be prevented

The implementation may choose the most reliable internal strategy, but the user-visible result must be that one successful implementation produces one appended log entry.

### 6. Failure Behavior

If the log file cannot be written:
- surface the failure clearly
- do not silently continue
- do not report a clean successful completion
- allow a deterministic partial-success result such as `completed_with_issues` when the implementation itself succeeded but the required log append did not

Avoid ambiguous states where implementation appears fully complete but the required implementation log entry was skipped without notice.

### 7. Integration Expectations

Integrate the logging behavior into the current spec-implementation workflow so that manual log edits are no longer required for normal execution-spec completion.

Reuse existing implementation-path services and command plumbing where practical.

### 8. Tests

Add focused coverage proving:

- active spec implementation appends exactly one correctly formatted log entry
- draft specs do not produce log entries
- duplicate appends do not occur for one implementation event
- required timestamp format is used
- write failures surface clearly and deterministically
- write failures do not appear as a clean successful completion
- log format matches `docs/features/README.md`
- all existing related implementation-flow tests still pass

## Non-Goals
- Do not log draft specs.
- Do not retroactively log past implementations.
- Do not redesign the implementation command.
- Do not invent a second log format.
- Do not broaden this into general project activity logging.

## Authority Rule
- Implementation-log format must match `docs/features/README.md` exactly.
- Auto-logging is part of successful active execution-spec completion, not an optional follow-up step.
- The implementation log remains append-only.

## Completion Signals
- Successful implementation of an active execution spec appends a correctly formatted implementation-log entry automatically.
- Draft specs are never logged as implemented.
- One completed implementation event produces one log entry.
- Write failures are surfaced clearly and deterministically.
- Write failures do not appear as a clean successful completion.
- Manual log editing is no longer required for normal execution-spec completion.
- All tests pass.

## Post-Execution Expectations
- The implementation log is kept current automatically as active execution specs are completed.
- Developers and agents no longer need to remember a manual log-append step.
- Execution-spec chronology becomes more reliable and harder to skip accidentally.
