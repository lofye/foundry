# Execution Spec: 002-spec-new-cli-command

## Feature
- execution-spec-system

## Purpose
- Provide a deterministic CLI command to create new draft specs.
- Eliminate ID collisions within a feature by enforcing canonical allocation rules.
- Standardize spec creation for both humans and agents.
- Deliver a polished, predictable CLI experience with clear output, strong validation, and immediately useful generated files.

## Scope
- Introduce a `spec:new` CLI command.
- Automatically allocate the next valid root spec ID within the target feature.
- Create a draft spec file in the correct location.
- Pre-fill the spec with a valid template.
- Define the exact CLI UX, command shape, validation behavior, success output, and failure output.

## Constraints
- Must use canonical ID allocation rules defined in `docs/features/README.md`.
- Must not allow duplicate IDs within a feature.
- Must always create specs in the drafts directory.
- Must be deterministic given the current filesystem state.
- Must not modify existing specs.
- Must feel polished and trustworthy for both humans and agents.
- Must fail clearly and actionably.

## Requested Changes

### 1. CLI Command

Introduce:

```bash
foundry spec:new <feature> "<slug>"
```

Example:

```bash
foundry spec:new execution-spec-system "add-cli-command"
```

The command creates a new draft spec using the next valid root ID for the target feature.

### 2. ID Allocation

The command must:
- scan existing specs in the target feature, including both draft and active specs
- determine the next available root ID using canonical allocation rules
- assign the next valid ID deterministically

Given identical repo state, repeated runs must choose the same next ID.

### 3. File Creation

The command must create:

```text
docs/features/<feature>/specs/drafts/<id>-<slug>.md
```

The command must create the feature directory and drafts directory if they do not already exist, as long as the feature name itself is valid.

### 4. Template Generation

The generated file must be pre-populated with this exact template:

```md
# Execution Spec: <id>-<slug>

## Feature
- <feature>

## Purpose

## Scope

## Constraints

## Requested Changes

## Non-Goals

## Authority Rule

## Completion Signals

## Post-Execution Expectations
```

The heading must mirror the filename only and must not include the feature path.

### 5. Input Validation

The command must validate the feature name and slug before creating the file.

Feature validation:
- feature name must be kebab-case
- feature name must not contain spaces or uppercase letters
- feature name must not contain path traversal or separator tricks
- invalid feature names must fail clearly

Slug validation:
- normalize slug input to lowercase kebab-case
- trim surrounding whitespace
- collapse internal spaces and separators into single hyphens
- remove leading and trailing hyphens
- reject an empty result after normalization
- reject obviously low-information slugs such as `new`, `spec`, `draft`, or similarly unhelpful placeholders

Slug uniqueness is not required.
Within a feature, identity uniqueness is enforced by ID, not by slug.
Different specs in the same feature may reuse the same slug if they have different IDs.

### 6. Exact Success UX

On success, the command must print concise, polished output in this exact structure:

```text
Created draft spec

Feature: execution-spec-system
ID: 002
Slug: add-cli-command
Path: docs/execution-spec-system/specs/drafts/002-add-cli-command.md

Next steps:
- Fill in the spec sections
- Keep the filename unchanged
- Promote by moving it out of drafts when ready
```

The command may substitute actual values, but the output structure and labels must remain stable.

### 7. Existing-File and Write-Safety Behavior

If the computed target path already exists, the command must fail without overwriting.

Required output structure:

```text
Could not create draft spec

Reason: target file already exists
Path: docs/execution-spec-system/specs/drafts/002-add-cli-command.md

Required action:
- Inspect existing specs in this feature
- Resolve the conflicting filesystem state
```

The command must never overwrite an existing file silently.

A reused slug with a newly allocated ID is valid and must not be treated as a collision.

### 8. Exact Invalid-Input Behavior

If the feature name is invalid, the command must fail using this structure:

```text
Could not create draft spec

Reason: invalid feature name
Feature: <provided-feature>

Required action:
- Use lowercase kebab-case
- Example: execution-spec-system
```

If the slug normalizes to an empty or invalid result, the command must fail using this structure:

```text
Could not create draft spec

Reason: invalid slug
Slug: <provided-slug>

Required action:
- Provide a meaningful kebab-case slug
- Example: add-cli-command
```

The command may include one additional diagnostic line when helpful, but must preserve this overall shape.

### 9. Exact Duplicate-ID and Allocation Failure Behavior

If the command cannot allocate a valid next ID deterministically, it must fail using this structure:

```text
Could not create draft spec

Reason: could not allocate next spec ID
Feature: execution-spec-system

Required action:
- Run `foundry spec:validate`
- Resolve duplicate or invalid spec state in this feature
```

This failure must be explicit. The command must not guess or recover silently.

### 10. Polished Operational Behavior

The command must:
- exit zero on success
- exit non-zero on failure
- produce plain text output suitable for terminals, logs, and LLM consumption
- avoid noisy banners, spinners, or decorative formatting
- keep output stable enough for automated parsing
- write only one file on success
- write no files on failure

### 11. Determinism

Given identical repo state and identical command input:
- the same spec ID must be allocated
- the same normalized slug must be produced
- the same path must be produced
- the same success or failure class must occur

### 12. Tests

Add coverage to prove:
- valid input creates the expected draft file
- the generated template matches the required structure
- ID allocation is deterministic
- slug normalization is deterministic
- invalid feature names fail clearly
- invalid slugs fail clearly
- existing-file conflicts fail without overwriting
- reused slugs with new IDs are allowed
- allocation failures fail clearly
- success output matches the required structure
- failure output matches the required structure

## Non-Goals
- Do not implement interactive prompts.
- Do not auto-promote drafts.
- Do not execute specs.
- Do not open an editor automatically.
- Do not write to `docs/features/implementation-log.md`.

## Authority Rule
- ID allocation must follow canonical rules.
- Filenames are the source of truth.
- IDs must be unique within a feature.
- The generated heading must mirror the filename only.
- The CLI UX defined in this spec is part of the required behavior, not an optional presentation detail.

## Completion Signals
- `foundry spec:new` creates a correctly named draft spec.
- IDs are correctly allocated with no collisions within a feature.
- The generated file uses the required template.
- Input normalization and validation behave deterministically.
- Reused slugs with new IDs are allowed.
- Success output follows the required structure.
- Failure output follows the required structure.
- Existing files are never overwritten.
- All tests pass.

## Post-Execution Expectations
- Developers and agents can create draft specs without manual ID management.
- Specs within a feature get unique IDs even when slugs repeat.
- The command feels polished, predictable, and safe.
- Spec creation becomes consistent, deterministic, and difficult to misuse.
