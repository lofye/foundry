# Execution Spec: 003-spec-state-alignment-engine

Implement Foundry Master Spec 35D3 — Spec-State Alignment Engine

Objective

Introduce explicit spec-vs-state alignment checking for feature context artifacts.

Implement:
•	an alignment checker
•	context check-alignment
•	deterministic mismatch detection heuristics
•	actionable issue reporting
•	PHPUnit coverage

Use the canonical file model, validators, and context services established in 35D1–35D2.

Do NOT implement inspect integration, verify integration, scaffold/doc updates, or advanced semantic inference.

⸻

Scope

Add command: context check-alignment

Support:

php bin/foundry context check-alignment --feature=<feature>
php bin/foundry context check-alignment --feature=<feature> --json

Single-feature support is required.

Optional --all support is allowed only if it is easy, clean, and fully consistent with the single-feature behavior. Do not force it if it complicates the implementation.

⸻

Purpose

After structural validation exists, Foundry must detect when:
•	the feature spec says one thing
•	the feature state says another
•	or a divergence exists without an associated decision record

This is the first real semantic enforcement layer for context anchoring, but it must remain conservative and deterministic.

⸻

Mismatch Rules

A feature is in mismatch when one or more of the following conditions are true:
1.	The spec describes requirements not reflected anywhere in:
•	Current State
•	Open Questions
•	Next Steps
2.	The state document describes current behavior or scope that is not grounded in:
•	the spec
•	or a decision entry that explains divergence
3.	Acceptance criteria appear omitted from state tracking entirely.
4.	Divergence appears to exist without a corresponding decision entry.

Do not require perfect semantic understanding.
Use deterministic heuristics that are explainable and testable.

⸻

Heuristic Guidance

Keep the initial alignment engine simple and explicit.

Acceptable early heuristics include:
•	comparing normalized bullet items across relevant sections
•	checking whether acceptance criteria phrases appear in state sections
•	checking whether divergence markers are referenced in decisions
•	identifying obvious orphaned state claims

Do not claim strong semantic certainty beyond what the implementation can justify.

Issue types should be framed as:
•	mismatch
•	possible_mismatch
•	unsupported_state_claim
•	untracked_spec_requirement
•	missing_decision_reference

Prefer clear issue codes and honest reporting over aggressive inference.

⸻

JSON Output Contract

For:

php bin/foundry context check-alignment --feature=<feature> --json

Use this stable top-level shape:

{
"status": "ok|warning|mismatch",
"feature": "event-bus",
"issues": [
{
"code": "untracked_spec_requirement",
"message": "Acceptance criteria item is not reflected in Current State, Open Questions, or Next Steps.",
"spec_section": "Acceptance Criteria",
"state_section": null,
"decision_reference_found": false
}
],
"required_actions": []
}

Requirements:
•	stable keys
•	deterministic ordering
•	no timestamps in command output
•	honest status mapping based on detected issues

⸻

Status Model

The command must return one of:
•	ok
•	warning
•	mismatch

Semantics

ok
No meaningful alignment issues detected.

warning
Possible or weaker alignment concerns exist, but the feature is not clearly in hard mismatch.

mismatch
Clear spec-vs-state divergence or unsupported claims exist.

Be consistent.

⸻

Required Actions

Return actionable repair guidance derived from detected issues.

Examples include:
•	reflect spec requirement in Current State, Open Questions, or Next Steps
•	log divergence in the decision ledger
•	update the spec to reflect actual intended behavior
•	update the feature state to reflect current implementation

Required actions must be:
•	concise
•	deterministic
•	directly tied to detected issues

⸻

Files to Create or Update

Create or update:
•	src/CLI/Commands/ContextCheckAlignmentCommand.php
•	src/Context/AlignmentChecker.php
•	src/Context/AlignmentIssue.php
•	src/Context/AlignmentResult.php

You may add helpers if needed, but keep the implementation minimal and explicit.

⸻

Responsibilities

AlignmentChecker
•	consume parsed spec, state, and decisions content
•	apply deterministic mismatch rules
•	return structured issues
•	distinguish between ok, warning, and mismatch
•	avoid speculative NLP-heavy behavior

ContextCheckAlignmentCommand
•	validate feature name
•	ensure required files can be loaded
•	return deterministic text and JSON output
•	surface actionable repair guidance

⸻

Constraints

Do NOT implement any of the following in this spec:
•	advanced natural language inference
•	LLM-based semantic matching
•	inspect integration
•	verify integration
•	AGENTS.md updates
•	APP-AGENTS.md updates
•	scaffold changes
•	broad repository workflow enforcement
•	hidden refusal-to-proceed behavior outside the command output

Keep this alignment engine conservative, explainable, and testable.

⸻

Tests (PHPUnit)

Unit tests
•	spec requirement missing from state is reported
•	state claim unsupported by spec is reported
•	divergence with decision reference is treated differently than divergence without one
•	empty or weakly populated state sections produce warning or mismatch consistently
•	output shape/result structure is stable

Integration tests
•	context check-alignment --feature=<feature> --json returns expected issues
•	compliant feature returns ok
•	obviously divergent feature returns mismatch

Keep tests explicit and readable.

⸻

Acceptance Criteria

The work is complete only when:
•	Foundry can check spec-state alignment for a feature
•	mismatches are reported using deterministic heuristics
•	decision-ledger-backed divergence is treated differently from unexplained divergence
•	text and JSON output are stable
•	all added tests pass

⸻

Final Instruction

Implement a conservative, deterministic alignment engine.
Before adding new parsing or loading logic, inspect the 35D2 implementation for reusable context services. Reuse existing context-file loading and aggregation code where practical. Do not introduce duplicate parsing paths unless clearly necessary.

Do not overreach into vague semantic inference.

Prefer explainable heuristics, stable issue codes, and honest reporting.
