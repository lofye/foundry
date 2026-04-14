# Execution Spec: 001-initial

Implement Foundry Master Spec 35D1 — Context Artifact Model, Templates, and Validators

Objective

Introduce the foundational document model for feature-level context anchoring, including:
•	canonical file paths
•	feature naming rules
•	markdown templates
•	validation services
•	PHPUnit coverage

This is internal infrastructure only. Do NOT add CLI commands, enforcement logic, or modify AGENTS files.

⸻

Canonical File Model

For each feature <feature-name>, support:
•	docs/features/<feature-name>.spec.md
•	docs/features/<feature-name>.md
•	docs/features/<feature-name>.decisions.md

These are the only canonical context artifact paths.

⸻

Execution Specs

Foundry may support optional execution specs used to guide implementation.

Canonical structure (recommended):

docs/specs/<feature>/<id>-<slug>.md

Examples:

docs/specs/blog/001-initial.md
docs/specs/blog/002-add-comments.md

These are not required context artifacts and are not validated by the context system in this phase.

Execution specs are not authoritative and must not replace the canonical feature spec.

They are introduced for use by later execution-oriented specs.

⸻

Canonical Spec Rule

Each feature must have exactly one canonical spec file:
•	docs/features/<feature-name>.spec.md

Do NOT support alternative spec filenames such as:
•	.spec.v2.md
•	.phase2.spec.md
•	-v2.spec.md

You do NOT need to scan the repo for duplicates yet, but structure the system so this can be enforced later.

⸻

Feature Naming Rules

Feature names must:
•	be lowercase
•	use kebab-case
•	match filenames exactly

Invalid if:
•	uppercase letters
•	spaces or underscores
•	leading/trailing dash
•	repeated dashes
•	characters outside [a-z0-9-]

⸻

Required Structures

Spec

Must contain:
•	# Feature Spec: <name>
•	## Purpose
•	## Goals
•	## Non-Goals
•	## Constraints
•	## Expected Behavior
•	## Acceptance Criteria
•	## Assumptions

Allow optional:
•	## Spec Version

⸻

State Document

Must contain:
•	# Feature: <name>
•	## Purpose
•	## Current State
•	## Open Questions
•	## Next Steps

⸻

Decision Ledger Entries

Each entry must contain:
•	### Decision: <title>
•	Timestamp: <ISO-8601>
•	**Context**
•	**Decision**
•	**Reasoning**
•	**Alternatives Considered**
•	**Impact**
•	**Spec Reference**

⸻

Classes to Implement

Create:
•	src/Context/ContextFileResolver.php
•	src/Context/FeatureNameValidator.php
•	src/Context/SpecValidator.php
•	src/Context/StateValidator.php
•	src/Context/DecisionLedgerValidator.php
•	src/Context/Validation/ValidationIssue.php
•	src/Context/Validation/ValidationResult.php

⸻

Responsibilities

ContextFileResolver
•	Return canonical paths for spec, state, decisions
•	Deterministic, no hidden logic

FeatureNameValidator
•	Validate naming rules
•	Return structured result (not boolean only)

SpecValidator
•	Validate existence (when requested)
•	Validate required sections
•	Validate top-level heading
•	Allow optional ## Spec Version
•	Only accept canonical spec filename pattern

StateValidator
•	Validate existence (when requested)
•	Validate required sections
•	Validate top-level heading

DecisionLedgerValidator
•	Validate structure of entries
•	Validate required sections per entry
•	No rewriting or normalization

⸻

Templates

Create:
•	stubs/context/spec.stub.md
•	stubs/context/state.stub.md
•	stubs/context/decisions.stub.md

Requirements:
•	Match required structures exactly
•	Deterministic placeholder text
•	No timestamps
•	No environment-specific content

⸻

Validation Model

Implement:
•	ValidationResult
•	valid
•	issues
•	missing_sections
•	file_exists
•	ValidationIssue
•	code
•	message
•	file_path
•	section (optional)

Use stable, machine-readable issue codes.

Do NOT define final JSON output yet.

⸻

Tests (PHPUnit)

FeatureNameValidator
•	valid passes
•	uppercase fails
•	underscore fails
•	space fails
•	invalid char fails
•	leading/trailing dash fails
•	repeated dashes fail

ContextFileResolver
•	resolves correct paths
•	deterministic
•	does not mutate input

SpecValidator
•	valid minimal spec passes
•	spec with ## Spec Version passes
•	missing file detected
•	missing section detected
•	malformed heading detected

StateValidator
•	valid minimal state passes
•	missing section detected
•	malformed heading detected

DecisionLedgerValidator
•	missing file detected
•	valid ledger passes
•	malformed entry fails
•	missing subsection fails

⸻

Constraints
•	Deterministic behavior only
•	No CLI commands
•	No enforcement/refusal logic
•	No AGENTS.md updates
•	No alignment checks
•	No repo-wide scanning

⸻

Acceptance Criteria
•	All validators implemented
•	All templates created
•	All tests pass
•	Canonical file model established
•	System ready for 35D2–35D6

⸻

Final Instruction

Implement exactly the above.

Do not expand scope.
Do not add interpretation.
Keep everything minimal, explicit, and deterministic.
