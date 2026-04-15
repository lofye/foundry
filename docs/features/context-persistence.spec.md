# Feature Spec: context-persistence

## Purpose
- Preserve feature intent, implementation state, and decision history across sessions.
- Make feature work resumable without relying on chat history.

## Goals
- Add canonical feature context artifacts under `docs/features/`.
- Support deterministic validation of those artifacts.
- Introduce CLI tooling to initialize, validate, inspect, and verify feature context.
- Introduce deterministic spec-state alignment checking.
- Introduce deterministic, context-driven feature execution.
- Introduce deterministic, spec-driven execution as a secondary entry point into feature execution.
- Introduce deterministic auto-planning from canonical feature context.
- Introduce deterministic planner output rendering through a canonical stub template.
- Support safe repair-first execution when context is invalid.

## Non-Goals
- Do not add model-specific behavior.
- Do not replace code/tests as the source of implementation truth.
- Do not compact or rewrite decision history.
- Do not allow prompt-only execution without canonical context.
- Do not make execution specs authoritative after implementation.

## Constraints
- Must remain deterministic.
- Must be compatible with multiple LLMs.
- Must use human-readable Markdown files.
- Must preserve exactly one canonical spec per feature.
- Alignment checking must remain conservative and explainable.
- Execution must fail closed unless context is valid or explicitly repaired through allowed repair flows.
- Planning must fail clearly when context cannot proceed or when no meaningful bounded next step can be derived.
- Generated execution specs must use one canonical structure.

## Expected Behavior
- Each feature has one canonical spec, one state document, and one decision ledger.
- Validators can check structure and required sections.
- CLI commands can initialize and validate feature context.
- `context doctor` detects when execution specs exist for a feature but one or more canonical feature context files are missing.
- `context doctor` reports execution-spec drift through the existing per-file issue buckets and required-actions model.
- CLI commands can detect spec-state mismatches using deterministic heuristics.
- Inspect context aggregates doctor and alignment results into a single deterministic view.
- Verify context maps doctor and alignment results to deterministic pass/fail semantics.
- Verify context surfaces doctor execution-spec drift issues through its existing flattened issue list.
- Verify context fails when doctor is `repairable` or `non_compliant`.
- Verify context fails when alignment status is `mismatch`.
- Inspect and verify reuse doctor and alignment services rather than reimplementing either path.
- Divergence backed by decision entries is treated differently from unexplained divergence.
- Implement feature consumes canonical feature context as authoritative execution input.
- Implement feature blocks execution when `can_proceed` is false unless explicit repair mode succeeds.
- Implement feature updates feature state and decision history after meaningful execution.
- Implement feature revalidates context after execution.
- Implement spec resolves execution specs deterministically from `docs/specs/<feature>/<id>-<slug>.md`, where `<id>` uses one or more dot-separated 3-digit segments.
- Execution spec headings mirror the filename only using `# Execution Spec: <id>-<slug>`.
- Implement spec reuses the existing feature execution pipeline rather than creating a second execution policy path.
- Implement spec blocks when execution-spec instructions conflict with canonical feature truth.
- Implement spec records that execution was driven by a specific execution spec without changing canonical authority.
- Plan feature generates the next bounded execution spec deterministically under `docs/specs/<feature>/<id>-<slug>.md`, where `<id>` uses one or more dot-separated 3-digit segments.
- Plan feature uses canonical feature context as authoritative planning input.
- Plan feature derives concrete planning gaps from Expected Behavior versus Current State.
- Plan feature generates non-tautological purpose, scope, requested changes, and slug output for concrete gaps.
- Plan feature fails clearly when context cannot proceed or when no bounded next step can be derived.
- Plan feature blocks when only abstract or non-actionable gaps remain.
- Planner output is deterministic and reproducible for identical canonical inputs.
- Generated execution specs are rendered from a canonical stub template.
- Planner allocation does not reuse root ids that are already present in active or draft execution-spec filenames.
- Later execution systems can consume canonical feature context files safely.

## Acceptance Criteria
- Canonical files exist for the feature.
- Required sections are present in canonical feature files.
- Validation passes.
- CLI can initialize missing context files deterministically.
- CLI can validate context and produce actionable repair guidance.
- `context doctor` emits `EXECUTION_SPEC_DRIFT` deterministically when execution specs exist but canonical feature context files are missing.
- CLI can detect spec-state alignment issues deterministically.
- Inspect context returns a deterministic combined context view.
- Verify context returns deterministic pass/fail status for feature context.
- Verify context surfaces `EXECUTION_SPEC_DRIFT` as a doctor-sourced issue without changing its output contract.
- Alignment results include actionable repair guidance.
- Implement feature executes only from canonical context artifacts.
- Implement feature returns deterministic blocked, repaired, completed, or completed_with_issues results.
- Implement feature updates state and decisions when execution changes feature reality.
- Implement feature revalidates context before finishing.
- Implement spec executes a discrete implementation spec without bypassing canonical context validation.
- Implement spec returns deterministic blocked, repaired, completed, or completed_with_issues results aligned with implement feature.
- Execution spec conflicts do not override canonical feature authority.
- Plan feature returns deterministic planned or blocked results.
- Plan feature creates an execution spec that is immediately usable by implement spec.
- Plan feature blocks rather than generating vague or self-referential execution specs.
- Identical canonical planning inputs produce identical planning outputs.
- Generated execution specs match the canonical stub structure exactly.
- Implement spec rejects noncanonical execution-spec headings.

## Assumptions
- Initial feature work may still be partly manual.
- Execution specs may exist separately under `docs/specs/<feature>/<id>-<slug>.md`.
- Execution specs are secondary work orders and do not override the canonical feature spec.
- Implement spec may consume execution specs as bounded work orders while canonical feature context remains authoritative.
- Plan feature may derive one bounded execution spec at a time from canonical feature context without generating a roadmap.
