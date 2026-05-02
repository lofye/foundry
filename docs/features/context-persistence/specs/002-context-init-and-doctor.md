# Execution Spec: 002-context-init-and-doctor

Implement Foundry Master Spec 35D2 — Context Init and Context Doctor

Objective

Introduce the first public CLI surface for Foundry’s context anchoring system.

Implement:
•	context init
•	context doctor
•	deterministic text output
•	deterministic JSON output
•	actionable repair guidance
•	PHPUnit coverage

Use the validators, templates, and canonical file model established in 35D1.

Do NOT implement alignment heuristics in full, inspect integration, verify integration, or AGENTS/APP-AGENTS changes yet.

⸻

Scope

Add command: context init

Support:

php bin/foundry context init <feature>
php bin/foundry context init <feature> --json

Behavior:
•	validate feature name first
•	ensure docs/features/ exists
•	create canonical files if missing:
•	spec
•	state
•	decisions
•	populate from 35D1 stubs
•	do not overwrite existing files
•	report which files were created
•	report which files already existed
•	remain deterministic

⸻

Add command: context doctor

Support:

php bin/foundry context doctor --feature=<feature>
php bin/foundry context doctor --feature=<feature> --json
php bin/foundry context doctor --all
php bin/foundry context doctor --all --json

Behavior for single feature:
•	validate feature name
•	resolve canonical file paths
•	validate spec
•	validate state document
•	validate decision ledger
•	determine overall status
•	produce actionable repair guidance

Behavior for --all:
•	inspect docs/features/
•	discover features from canonical file names only
•	group related files deterministically
•	validate all discovered features
•	return deterministic ordering

If both --feature and --all are supplied, fail with a clear deterministic error.

⸻

Canonical File Model

For each feature <feature-name>, use only:
•	docs/<feature-name>/<feature-name>.spec.md
•	docs/<feature-name>/<feature-name>.md
•	docs/<feature-name>/<feature-name>.decisions.md

Do not treat alternate spec filenames as valid feature context files.

⸻

Status Model

context doctor must return one of these top-level statuses:
•	ok
•	warning
•	repairable
•	non_compliant

Semantics

ok
All required files exist and pass structural validation.

warning
Files exist and are structurally valid, but there are non-fatal concerns.

repairable
Required files are missing or malformed in ways that can be corrected by creating or editing feature context files.

non_compliant
Feature context is invalid in a more serious way, such as invalid feature naming, incompatible file grouping, or other hard-stop conditions.

Be consistent.

⸻

JSON Contract — context doctor --json

For a single feature, use this stable top-level shape:

{
"status": "ok|warning|repairable|non_compliant",
"feature": "event-bus",
"files": {
"spec": {
"path": "docs/event-bus/event-bus.spec.md",
"exists": true,
"valid": true,
"missing_sections": [],
"issues": []
},
"state": {
"path": "docs/event-bus/event-bus.md",
"exists": true,
"valid": true,
"missing_sections": [],
"issues": []
},
"decisions": {
"path": "docs/event-bus/event-bus.decisions.md",
"exists": true,
"valid": true,
"issues": []
}
},
"required_actions": []
}

Requirements:
•	stable key ordering
•	deterministic output
•	no timestamps in doctor output

For --all, return a deterministic aggregate shape consistent with existing Foundry JSON command conventions.

⸻

JSON Expectations — context init --json

Include enough information for an LLM to know:
•	whether the operation succeeded
•	whether the feature name was valid
•	which files were created
•	which files already existed

Keep the shape simple, deterministic, and machine-usable.

Do not invent unnecessary fields.

⸻

Required Actions

context doctor must return actionable repair guidance.

Examples of required actions include:
•	create missing spec file
•	create missing state file
•	create missing decision ledger
•	fix malformed spec heading
•	add missing required section

Required actions must be:
•	concise
•	deterministic
•	directly derived from detected issues

⸻

Files to Create or Update

Create or update:
•	src/CLI/Commands/ContextInitCommand.php
•	src/CLI/Commands/ContextDoctorCommand.php
•	src/Context/ContextInitService.php
•	src/Context/ContextDoctorService.php

You may add small helper classes if needed, but keep the implementation minimal.

⸻

Responsibilities

ContextInitCommand / ContextInitService
•	validate feature names
•	create directory structure if needed
•	create missing canonical files from stubs
•	do not overwrite existing files by default
•	return deterministic result data

ContextDoctorCommand / ContextDoctorService
•	support single-feature and all-feature modes
•	aggregate validator results from 35D1
•	calculate top-level status consistently
•	emit deterministic text and JSON output
•	produce actionable repair guidance

⸻

Constraints

Do NOT implement any of the following in this spec:
•	full spec-vs-state mismatch detection
•	semantic alignment heuristics
•	context check-alignment
•	inspect context
•	verify context
•	AGENTS.md updates
•	APP-AGENTS.md updates
•	scaffold promotion changes
•	refusal-to-proceed logic beyond status/reporting
•	full duplicate-spec discovery beyond canonical filename handling for discovered files

⸻

Tests (PHPUnit)

Unit tests
•	init service creates missing files correctly
•	init service does not overwrite existing files
•	doctor service maps validation results to statuses consistently
•	doctor service generates required actions correctly

Integration tests
•	context init <feature> creates the 3 canonical files
•	context init with invalid feature name fails deterministically
•	context doctor --feature=<feature> --json returns the required contract
•	context doctor --all --json returns deterministic ordering and results
•	malformed docs are reported correctly
•	missing docs produce repairable or non_compliant consistently
•	supplying both --feature and --all fails deterministically

Keep tests explicit and readable.

⸻

Acceptance Criteria

The work is complete only when:
•	php bin/foundry context init <feature> creates the canonical files deterministically
•	existing files are not overwritten
•	php bin/foundry context doctor --feature=<feature> --json returns the defined contract
•	php bin/foundry context doctor --all --json validates all discovered features deterministically
•	overall status mapping is stable and tested
•	all added tests pass

⸻

Final Instruction

Implement exactly the above.

Keep scope tight.

Do not expand into alignment, inspect, verify, scaffold, or AGENTS work yet.

Keep everything minimal, explicit, and deterministic.
