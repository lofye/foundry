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
- Feature-scoped doctor diagnostics evaluate through one normalized internal rule model that maps to the existing doctor and verify-context contracts.
- `context doctor` detects when execution specs exist for a feature but one or more canonical feature context files are missing.
- `context doctor` reports execution-spec drift through the existing per-file issue buckets and required-actions model.
- `context doctor` detects stale completed work that remains listed in `Next Steps`.
- `context doctor` detects current-state divergence from the canonical spec when no supporting decision entry exists.
- `context doctor` coalesces overlapping rule results, duplicate issues, and duplicate required actions deterministically before returning output.
- CLI commands can detect spec-state mismatches using deterministic heuristics.
- Inspect context aggregates doctor and alignment results into a single deterministic view.
- Verify context maps doctor and alignment results to deterministic pass/fail semantics.
- Verify context derives a per-feature `consumable` flag from existing doctor, alignment, and required-action outputs without changing doctor or alignment rules.
- Verify context surfaces doctor execution-spec drift issues through its existing flattened issue list.
- Verify context coalesces duplicate doctor issues and duplicate required actions without changing its outer JSON contract.
- Verify context fails when doctor is `repairable` or `non_compliant`.
- Verify context fails when alignment status is `mismatch`.
- Verify context reports `consumable = true` only when doctor status is `ok`, alignment status is `ok`, and required actions are empty.
- Repo-wide verify context keeps its existing pass/fail status semantics but sets top-level `can_proceed = false` when any feature is not consumable.
- Active execution specs require exact implementation-log coverage through a deterministic verification surface, while draft specs remain exempt.
- Foundry can output the exact canonical implementation-log entry content for one active execution spec through a deterministic CLI-owned surface.
- Inspect and verify reuse doctor and alignment services rather than reimplementing either path.
- Feature state documents normalize through one reusable deterministic normalization path before framework-owned state updates are persisted.
- Canonical feature spec documents normalize through the reusable context normalization infrastructure before framework-owned spec updates are persisted.
- `context repair` exists as an explicit CLI-owned repair surface for one feature at a time.
- `context repair` reuses existing inspect and verify analysis and applies only safe normalization-style repairs to canonical feature spec and state documents.
- `context repair` fails clearly when critical canonical context inputs are missing, does not auto-write decision-ledger content, and leaves ambiguous semantic divergence for manual action.
- `context repair` returns deterministic `repaired`, `no_changes`, `blocked`, or `failed` results and computes `can_proceed` from post-repair consumability rather than pre-repair state.
- Divergence backed by decision entries is treated differently from unexplained divergence.
- Implement feature consumes canonical feature context as authoritative execution input.
- Implement feature refuses execution when canonical context is not consumable unless explicit repair mode succeeds.
- Implement feature updates feature state and decision history after meaningful execution.
- Implement feature revalidates context after execution.
- Implement spec resolves active execution specs deterministically from canonical `<feature>/<id>-<slug>` refs, from exact `<feature> <id>` shorthand within a feature, and from a unique active `<id>-<slug>` shorthand, where `<id>` uses one or more dot-separated 3-digit segments.
- Implement spec `<feature> <id>` resolution matches the canonical hierarchical id exactly, resolves active specs only, and fails clearly for malformed ids, unknown features, unknown active ids, draft-only matches, or ambiguous duplicates.
- Execution spec headings mirror the filename only using `# Execution Spec: <id>-<slug>`.
- Implement spec reuses the existing feature execution pipeline rather than creating a second execution policy path.
- Implement spec blocks when execution-spec instructions conflict with canonical feature truth.
- Implement spec conflict detection is polarity-aware and does not treat equivalent prohibitions as contradictions.
- Implement spec conflict detection requires opposing polarity plus a substantially similar target action before blocking.
- Parsed execution-spec prohibition bullets preserve their negative parent context when conflict checks evaluate nested instruction items.
- Implement spec records that execution was driven by a specific execution spec without changing canonical authority.
- Later context-dependent execution systems surface a deterministic `context_not_consumable` refusal when canonical context is not safely consumable.
- Plan feature generates the next bounded execution spec deterministically under `docs/specs/<feature>/drafts/<id>-<slug>.md`, where `<id>` uses one or more dot-separated 3-digit segments.
- Plan feature writes at most one draft execution spec file per successful invocation and verifies that its reported `spec_id` and `spec_path` match the actual filesystem side effect.
- Plan feature uses canonical feature context as authoritative planning input.
- Plan feature derives concrete planning gaps from Expected Behavior versus Current State.
- Plan feature generates non-tautological purpose, scope, requested changes, and slug output for concrete gaps.
- Plan feature blocks when the next candidate collapses into a generic fallback slug or title rather than bounded work.
- Plan feature derives slugs only from concrete bounded work and fails closed when no meaningful slug can be derived.
- Plan feature rejects low-information purpose, scope, requested changes, or completion signals rather than writing a weak execution spec.
- Plan feature uses completion signals that describe the bounded step itself rather than broad project-health signals.
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
- Feature-scoped doctor diagnostics use a normalized internal rule result that can be rendered into doctor file buckets and verify-context flattened issues deterministically.
- `context doctor` emits `EXECUTION_SPEC_DRIFT` deterministically when execution specs exist but canonical feature context files are missing.
- `context doctor` emits `STALE_COMPLETED_ITEMS_IN_NEXT_STEPS` deterministically when `Next Steps` still lists work already reflected in `Current State`.
- `context doctor` emits `DECISION_MISSING_FOR_STATE_DIVERGENCE` deterministically when `Current State` diverges from the canonical spec without a supporting decision entry.
- `context doctor` deduplicates overlapping rule results and coalesces duplicate required actions deterministically without changing its JSON shape.
- CLI can detect spec-state alignment issues deterministically.
- Feature state normalization keeps canonical section order and conservatively removes duplicate or obviously stale bullets without inventing content.
- Feature spec normalization keeps canonical section order, deterministic spacing, and safe exact-duplicate cleanup without changing intended meaning.
- `context repair` repairs only safe normalization-style issues in canonical feature spec and state documents when explicitly invoked.
- `context repair` refuses missing-critical-input and ambiguous semantic repairs without inventing content or modifying decision ledgers.
- `context repair` returns deterministic `repaired`, `no_changes`, `blocked`, or `failed` JSON results and computes `can_proceed` and manual-action status from post-repair consumability.
- Inspect context returns a deterministic combined context view.
- Verify context returns deterministic pass/fail status for feature context.
- Verify context includes `consumable` per feature and derives it strictly from doctor status, alignment status, and required actions.
- Verify context surfaces `EXECUTION_SPEC_DRIFT` as a doctor-sourced issue without changing its output contract.
- Verify context coalesces duplicate doctor issues and required actions deterministically without changing its JSON shape.
- Repo-wide verify context sets `can_proceed = false` when any feature is not consumable even if all feature statuses still render as `pass`.
- Deterministic verification fails when an active execution spec is missing an exact matching implementation-log entry, and drafts do not require log coverage.
- Foundry returns deterministic machine-readable canonical implementation-log entry content for an active execution spec and rejects draft-only or invalid targets clearly.
- Alignment results include actionable repair guidance.
- Implement feature executes only from canonical context artifacts.
- Implement feature blocks with a deterministic `context_not_consumable` refusal when canonical context is not safely consumable.
- Implement feature returns deterministic blocked, repaired, completed, or completed_with_issues results.
- Implement feature updates state and decisions when execution changes feature reality.
- Implement feature revalidates context before finishing.
- Implement spec executes a discrete implementation spec without bypassing canonical context validation.
- Implement spec reuses the same `context_not_consumable` refusal gate as implement feature.
- Implement spec returns deterministic blocked, repaired, completed, or completed_with_issues results aligned with implement feature.
- Implement spec resolves active execution specs deterministically from `<feature> <id>` without accepting draft-only or malformed shorthand targets.
- Execution spec conflicts do not override canonical feature authority.
- Equivalent prohibition wording is not blocked as a canonical conflict.
- True instruction contradictions with opposing polarity still block deterministically.
- Plan feature returns deterministic planned or blocked results.
- Plan feature creates exactly one draft execution spec whose reported `spec_id` and `spec_path` match the file actually written.
- Plan feature does not write active execution specs directly and planned draft specs require promotion before `implement spec`.
- Plan feature blocks rather than generating vague or self-referential execution specs.
- Plan feature blocks generic fallback slugs and low-information execution-spec candidates instead of writing them.
- Planned completion signals remain bounded to the requested step rather than broad project-health checks.
- Identical canonical planning inputs produce identical planning outputs.
- Generated execution specs match the canonical stub structure exactly.
- Implement spec rejects noncanonical execution-spec headings.

## Assumptions
- Initial feature work may still be partly manual.
- Execution specs may exist separately under `docs/specs/<feature>/<id>-<slug>.md`.
- Execution specs are secondary work orders and do not override the canonical feature spec.
- Implement spec may consume execution specs as bounded work orders while canonical feature context remains authoritative.
- Plan feature may derive one bounded execution spec at a time from canonical feature context without generating a roadmap.
