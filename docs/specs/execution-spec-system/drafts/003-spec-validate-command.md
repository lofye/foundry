# Execution Spec: 003-spec-validate-command

## Feature
- execution-spec-system

## Purpose
- Provide a command to validate all specs against canonical rules.
- Prevent drift and enforce system-wide consistency.

## Scope
- Validate filenames, IDs, structure, and placement.
- Detect collisions and rule violations.
- Provide clear, actionable error messages.

## Constraints
- Must not modify files.
- Must be deterministic.
- Must validate both draft and active specs.

## Requested Changes

### 1. CLI Command

Introduce:

```bash
foundry spec:validate
```

### 2. Filename Validation

Ensure all specs follow:

```text
<id>-<slug>.md
```

Check:
- valid padded segments
- valid kebab-case slug

### 3. ID Validation

Ensure:
- all IDs are valid format
- no duplicate IDs within a feature
- hierarchy is well-formed

### 4. Heading Validation

Ensure first line is:

```md
# Execution Spec: <id>-<slug>
```

### 5. Directory Validation

Ensure:
- drafts are only in `drafts/`
- active specs are not in `drafts/`

### 6. Metadata Validation

Ensure specs do not contain:
- `id`
- `parent`
- `status`

### 7. Output

The command must:
- print all violations
- exit non-zero if any violations exist

## Non-Goals
- Do not fix issues automatically.
- Do not rewrite files.

## Authority Rule
- Validation rules must match `docs/specs/README.md` exactly.

## Completion Signals
- Invalid specs are detected reliably.
- Duplicate IDs are detected.
- Incorrect headings are detected.
- The command exits correctly on failure.
- All tests pass.

## Post-Execution Expectations
- The spec system remains consistent over time.
- Agents and developers get immediate feedback on violations.
