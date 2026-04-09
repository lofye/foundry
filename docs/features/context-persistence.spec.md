# Feature Spec: context-persistence

## Purpose
- Preserve feature intent, implementation state, and decision history across sessions.
- Make feature work resumable without relying on chat history.

## Goals
- Add canonical feature context artifacts under docs/features/.
- Support deterministic validation of those artifacts.
- Introduce CLI tooling to initialize, validate, inspect, and verify feature context.
- Introduce deterministic spec-state alignment checking.
- Support future execution driven by canonical feature context.

## Non-Goals
- Do not add model-specific behavior.
- Do not replace code/tests as the source of implementation truth.
- Do not compact or rewrite decision history.

## Constraints
- Must remain deterministic.
- Must be compatible with multiple LLMs.
- Must use human-readable Markdown files.
- Must preserve exactly one canonical spec per feature.
- Alignment checking must remain conservative and explainable.

## Expected Behavior
- Each feature has one canonical spec, one state document, and one decision ledger.
- Validators can check structure and required sections.
- CLI commands can initialize and validate feature context.
- CLI commands can detect spec-state mismatches using deterministic heuristics.
- Inspect context aggregates doctor and alignment results into a single deterministic view.
- Verify context maps doctor and alignment results to deterministic pass/fail semantics.
- Verify context fails when doctor is repairable or non_compliant.
- Verify context fails when alignment status is mismatch.
- Inspect and verify reuse doctor and alignment services rather than reimplementing either path.
- Divergence backed by decision entries is treated differently from unexplained divergence.
- Later execution systems can consume canonical feature context files safely.

## Acceptance Criteria
- Canonical files exist for the feature.
- Required sections are present in canonical feature files.
- Validation passes.
- CLI can initialize missing context files deterministically.
- CLI can validate context and produce actionable repair guidance.
- CLI can detect spec-state alignment issues deterministically.
- Inspect context returns a deterministic combined context view.
- Verify context returns deterministic pass/fail status for feature context.
- Alignment results include actionable repair guidance.

## Assumptions
- Initial feature work may still be partly manual.
- Execution specs may exist separately under docs/specs/<feature>/<NNN-name>.md
