### Decision: separate intent, state, and reasoning into three files
Timestamp: 2026-04-07T12:00:00-04:00

**Context**
- Chat history is ephemeral and does not reliably preserve feature intent across sessions.

**Decision**
- Use three canonical feature files: spec, state, and decision ledger.

**Reasoning**
- This keeps intent, current reality, and historical reasoning distinct and easier to validate.

**Alternatives Considered**
- Keep everything in one file.
- Use only execution specs.
- Rely on chat history and code only.

**Impact**
- The system is more structured and easier to resume, but requires disciplined updates.

**Spec Reference**
- Purpose
- Goals
- Constraints

### Decision: introduce CLI surface for context initialization and validation
Timestamp: 2026-04-07T12:30:00-04:00

**Context**
- Context artifacts exist but must currently be created and validated manually.
- This limits usability and prevents consistent enforcement.

**Decision**
- Introduce CLI commands to initialize and validate feature context:
  - context init
  - context doctor

**Reasoning**
- A CLI surface makes the system usable for both humans and LLMs.
- Deterministic outputs allow future automation and enforcement layers.

**Alternatives Considered**
- Keep context creation manual.
- Delay CLI until later phases.
- Use non-deterministic or conversational tooling.

**Impact**
- Enables consistent creation and validation of feature context.
- Forms the foundation for later enforcement and execution phases.

**Spec Reference**
- Goals
- Expected Behavior
- Acceptance Criteria

### Decision: add deterministic spec-state alignment checking
Timestamp: 2026-04-07T13:30:00-04:00

**Context**
- Structural validation alone cannot detect drift between intended behavior and recorded feature state.
- The context system needs a deterministic semantic layer before inspect, verify, and enforcement can be trusted.

**Decision**
- Add a conservative alignment engine and CLI command:
  - context check-alignment

**Reasoning**
- Alignment checking is necessary to detect untracked requirements, unsupported state claims, and unexplained divergence.
- Deterministic heuristics are easier to test, explain, and trust than aggressive semantic inference.
- Decision-backed divergence must be handled differently from unexplained divergence.

**Alternatives Considered**
- Rely on manual review only.
- Delay alignment until later phases.
- Use LLM-based semantic matching immediately.

**Impact**
- Foundry can now detect meaningful mismatches between spec and state.
- The context system now has a semantic validation layer in addition to structural validation.
- This enables later inspect, verify, and refusal-to-proceed phases.

**Spec Reference**
- Goals
- Constraints
- Expected Behavior
- Acceptance Criteria

### Decision: compose doctor and alignment into inspect and verify workflows
Timestamp: 2026-04-07T14:00:00-04:00

**Context**
- Structural validation and semantic alignment existed separately, but there was no unified inspection or verification surface.
- Later enforcement phases need a deterministic proceed/fail signal rather than ad hoc interpretation of multiple commands.

**Decision**
- Add:
  - inspect context
  - verify context
- Reuse doctor and alignment services rather than reimplementing either path.

**Reasoning**
- A single inspection surface improves visibility.
- A deterministic verification surface provides a clean machine-readable gate for future enforcement.
- Reuse preserves consistency and reduces duplicate logic.

**Alternatives Considered**
- Keep doctor and alignment as separate manual checks only.
- Reimplement validation and alignment inside inspect and verify.
- Delay verify semantics until later phases.

**Impact**
- Foundry now has a unified context inspection workflow.
- Foundry now has deterministic pass/fail semantics for feature context.
- This creates the clean proceed/fail boundary needed for 35D5.

**Spec Reference**
- Goals
- Expected Behavior
- Acceptance Criteria

### Decision: promote context workflow guidance into framework and scaffold onboarding
Timestamp: 2026-04-07T14:30:00-04:00

**Context**
- Context commands were implemented, but framework and scaffold guidance still described the workflow as not yet available.
- This created drift between the documented onboarding path and the actual CLI behavior.

**Decision**
- Update framework and scaffold onboarding guidance to describe the implemented context workflow.
- Use verify context as the primary machine-readable proceed/fail gate.

**Reasoning**
- Onboarding docs must reflect real command behavior once the contract exists.
- A single documented gate reduces ambiguity for both humans and automation.

**Alternatives Considered**
- Leave bootstrap-only wording in place.
- Document different proceed/fail gates across framework and app scaffolds.
- Delay onboarding updates until later phases.

**Impact**
- Framework and scaffold guidance now match the implemented context system.
- New apps inherit the same deterministic context workflow expectations as the framework repo.

**Spec Reference**
- Goals
- Expected Behavior
- Acceptance Criteria

### Decision: expose explicit readiness signals for context enforcement
Timestamp: 2026-04-07T15:00:00-04:00

**Context**
- Doctor, alignment, inspect, and verify each reported context health, but later execution phases need a single explicit readiness interpretation.
- The workflow needed a deterministic answer to whether meaningful implementation may proceed.

**Decision**
- Expose can_proceed and requires_repair consistently across context doctor, context check-alignment, inspect context, and verify context.
- Keep refusal-to-proceed semantics aligned across CLI output and onboarding guidance.

**Reasoning**
- A shared readiness model keeps inspection, verification, and later enforcement layers consistent.
- Explicit readiness signals reduce hidden interpretation by users and tooling.

**Alternatives Considered**
- Infer readiness separately in each command.
- Keep pass/fail semantics only in verify context.
- Delay readiness hardening until feature execution exists.

**Impact**
- Later execution commands can reuse the existing context readiness contract without inventing a second policy path.
- Users and automation now receive the same deterministic readiness signals from every context surface.

**Spec Reference**
- Constraints
- Expected Behavior
- Acceptance Criteria

### Decision: accept exact feature-plus-id shorthand for implement spec
Timestamp: 2026-04-16T09:45:01-04:00

**Context**
- `implement spec` already resolved active execution specs deterministically, but the CLI still required either the full `<feature>/<id>-<slug>` ref or a globally unique filename shorthand.
- Developers and agents often already know the target feature and canonical numeric id before they need the slug text.
- Convenience could not weaken active-versus-draft rules or deterministic failure behavior.

**Decision**
- Accept `implement spec <feature> <id>` as a second deterministic invocation form.
- Resolve the id only within the provided feature, require an exact canonical hierarchical-id match, and fail clearly for malformed, unknown, draft-only, or ambiguous targets.

**Reasoning**
- Feature-plus-id shorthand removes unnecessary slug typing without guessing.
- Restricting resolution to one feature preserves determinism and avoids cross-feature ambiguity.
- Explicit malformed-id and draft-only failures keep canonical execution-spec lifecycle rules intact.

**Alternatives Considered**
- Keep only full feature-qualified refs and unique filename shorthand.
- Add fuzzy slug lookup or partial-id guessing.
- Auto-promote draft specs when no active match exists.

**Impact**
- `implement spec` is faster to invoke when the feature and canonical id are already known.
- Existing full-ref and unique filename shorthand behavior remains available.
- Active-only resolution and deterministic blocked results remain unchanged in principle.

**Spec Reference**
- Constraints
- Expected Behavior
- Acceptance Criteria

### Decision: make canonical conflict detection prohibition-aware
Timestamp: 2026-04-15T16:05:00-04:00

**Context**
- Canonical conflict detection already blocked obvious execution-spec contradictions, but it still relied too heavily on shared topic words when prohibition language was involved.
- Equivalent negative formulations such as `Do not append log entries for draft specs.` and `must not log draft specs` could still look contradictory if the detector ignored polarity and narrowed clauses down to overlapping nouns.

**Decision**
- Compare canonical and execution-spec instruction clauses with explicit polarity awareness.
- Treat aligned prohibitions as non-conflicting, require opposing polarity before blocking, and preserve nested negative lead-in context in parsed execution-spec instruction items.

**Reasoning**
- Shared nouns such as `log`, `entries`, or `draft specs` are not enough to prove a contradiction without polarity and target alignment.
- Preserving full negative clause context keeps conflict checks deterministic while avoiding false positives from orphaned bullet fragments.
- Tightening the matcher is safer than weakening the blocked-result contract around real canonical conflicts.

**Alternatives Considered**
- Keep relying on shared lexical overlap plus stripped forbidden clauses.
- Add broader semantic or LLM-based contradiction detection.
- Suppress conflict checks for all negative execution-spec instructions.

**Impact**
- Equivalent prohibitions are no longer blocked as canonical conflicts.
- True contradictions with opposing polarity and substantially similar target actions still fail deterministically.
- Execution-spec workflows are less noisy and more trustworthy when canonical rules use prohibition language.

**Spec Reference**
- Constraints
- Expected Behavior
- Acceptance Criteria

### Decision: fail closed on generic planner fallback output
Timestamp: 2026-04-15T15:10:00-04:00

**Context**
- `plan feature` already blocked abstract planning gaps, but weak candidates could still collapse into low-information fallback slugs such as `initial`.
- Planner-generated completion signals also still included broad project-health checks that did not verify the bounded step itself.

**Decision**
- Fail closed when planner output collapses into a generic fallback slug or other low-information execution-spec content.
- Accept planner candidates only when slug, purpose, scope, requested changes, and completion signals all remain concrete and bounded to the step itself.

**Reasoning**
- A blocked planning result is safer than writing an execution spec that looks valid but carries almost no actionable information.
- Slugs and completion signals should reflect the bounded work order itself so generated specs stay trustworthy as the next execution input.
- Quality-gating planner output keeps determinism intact without introducing speculative or model-driven refinement.

**Alternatives Considered**
- Keep allowing fallback slugs such as `initial` when no better slug can be derived.
- Accept broad project-health completion signals as sufficient planner output.
- Solve weak plans by rewriting canonical context automatically.

**Impact**
- `plan feature` now blocks low-information fallback specs instead of writing misleading files.
- Generated execution specs now use completion signals that verify the bounded step itself rather than broad repository health.
- Concrete bounded plans still generate deterministically when canonical context contains a meaningful next step.

**Spec Reference**
- Constraints
- Expected Behavior
- Acceptance Criteria

### Decision: generalize feature state normalization into a reusable path
Timestamp: 2026-04-15T13:31:49-04:00

**Context**
- The `context-persistence` state document had already been cleaned up manually, but the framework still lacked a reusable way to normalize other feature state documents.
- Without a shared normalization path, future state updates could reintroduce ordering noise, duplicate bullets, and stale completed leftovers that were unrelated to real feature drift.

**Decision**
- Introduce a reusable deterministic state-document normalizer for feature state files.
- Normalize `Current State`, `Open Questions`, and `Next Steps` into canonical section order when present.
- Integrate the normalizer into the framework-owned state write path used by context execution updates.

**Reasoning**
- A dedicated normalizer keeps state cleanup explicit and reusable without turning the framework into a broad markdown formatter.
- Canonical state ordering and conservative stale-item cleanup reduce noisy diffs while preserving current meaning.
- Integrating at the existing state write path makes the behavior real immediately and keeps future reuse cheap.

**Alternatives Considered**
- Keep state normalization as one-off manual cleanup only.
- Add a repository-wide markdown formatter for context files.
- Normalize feature specs and decision ledgers in the same pass.

**Impact**
- Framework-owned state updates now persist normalized feature state documents deterministically.
- Duplicate bullets and obvious stale completed leftovers are removed more consistently.
- Later drift-detection and planning work can rely on cleaner canonical state inputs.

**Spec Reference**
- Constraints
- Expected Behavior
- Acceptance Criteria

### Decision: normalize internal context-doctor rule evaluation
Timestamp: 2026-04-15T10:12:54-04:00

**Context**
- `EXECUTION_SPEC_DRIFT` had been added successfully, but its logic lived directly inside `ContextDoctorService`.
- Future feature-scoped doctor diagnostics would become harder to add consistently if rule evaluation, file-bucket attachment, required-action contribution, and verify flattening stayed ad hoc.

**Decision**
- Introduce a normalized internal rule model for feature-scoped context-doctor diagnostics.
- Represent doctor rules through stable normalized results that carry code, message, targets, repair semantics, and required actions.
- Use that rule model to drive `EXECUTION_SPEC_DRIFT`, doctor file-bucket rendering, and centralized doctor-to-verify issue flattening.

**Reasoning**
- A normalized rule model makes future doctor checks cheaper to add without changing external command contracts.
- Centralized mapping keeps doctor and verify deterministic and avoids rule-specific branching in verify-context.
- Keeping the external JSON unchanged preserves compatibility for users and automation while improving the internal structure.

**Alternatives Considered**
- Keep adding rule-specific conditionals inside `ContextDoctorService` and `ContextInspectionService`.
- Expose a new public diagnostics schema just for doctor rules.
- Delay internal cleanup until several more doctor rules existed.

**Impact**
- `context doctor` and `verify context` keep their current external output shapes.
- `EXECUTION_SPEC_DRIFT` now serves as the reference implementation for future doctor rules.
- Future feature-scoped diagnostics can be added with less duplication and lower regression risk.

**Spec Reference**
- Constraints
- Expected Behavior
- Acceptance Criteria

### Decision: diagnose execution-spec drift through existing doctor and verify pipelines
Timestamp: 2026-04-15T09:57:48-04:00

**Context**
- Execution specs can now accumulate under `docs/specs/<feature>/` and `docs/specs/<feature>/drafts/`, but canonical feature context files may still be missing for a target feature.
- Without an explicit diagnostic, agents and developers could mistake execution specs for authoritative context when running doctor or verify workflows.

**Decision**
- Detect execution-spec drift in `context doctor` whenever execution specs exist for a feature and one or more canonical feature context files are missing.
- Attach `EXECUTION_SPEC_DRIFT` to the missing canonical file buckets and reuse existing required-actions guidance.
- Surface the same doctor issue through `verify context` without changing the flattened verification contract.

**Reasoning**
- Canonical feature context must remain the only authoritative source of intent and state.
- Reusing existing doctor and verify issue shapes keeps diagnostics deterministic and machine-readable for automation.
- Repair guidance should steer users toward initializing canonical context rather than treating execution specs as substitutes.

**Alternatives Considered**
- Add a new top-level doctor diagnostics collection just for execution-spec drift.
- Ignore execution-spec presence until later execution-spec validation phases.
- Infer missing canonical context from execution specs automatically.

**Impact**
- Doctor and verify now fail closed when execution specs exist without complete canonical feature context.
- Missing canonical files are surfaced alongside explicit warnings not to rely on execution specs as source of truth.
- Feature discovery for all-feature doctor and verify runs now includes execution-spec directories when they contain planning artifacts.

**Spec Reference**
- Constraints
- Expected Behavior
- Acceptance Criteria

### Decision: adopt hierarchical padded execution-spec filenames and filename-only headings
Timestamp: 2026-04-14T10:05:00-04:00

**Context**
- Foundry’s execution-spec workflows were using a mix of feature-prefixed headings and single-segment filename assumptions even though spec placement and naming were already evolving into a broader contract.
- The system needed a canonical rule that keeps ordering deterministic and removes identity duplication from the spec body.

**Decision**
- Use `docs/specs/<feature>/<id>-<slug>.md` as the canonical execution-spec path, with `<id>` composed of one or more dot-separated 3-digit segments.
- Make execution-spec headings mirror the filename only as `# Execution Spec: <id>-<slug>`.
- Keep root-id planning draft-aware so existing active or draft ids are not reused accidentally.

**Reasoning**
- Padded segments preserve logical ordering under ordinary lexical sort.
- Filename-only headings align the visible document identity with the actual canonical identity.
- Scanning both active and draft ids preserves immutability and avoids accidental collisions during planning.

**Alternatives Considered**
- Keep single-segment `NNN-name` assumptions in the planner and resolver.
- Preserve feature-prefixed headings.
- Ignore draft ids when allocating the next planned execution spec.

**Impact**
- `implement spec`, `plan feature`, templates, docs, and tests now share one execution-spec naming contract.
- Execution specs remain secondary work orders, but their identity and hierarchy are now clearer and more deterministic.

**Spec Reference**
- Constraints
- Expected Behavior
- Acceptance Criteria

### Decision: execute feature work from canonical context with bounded repair
Timestamp: 2026-04-07T15:30:00-04:00

**Context**
- Foundry could inspect and verify feature context, but it still lacked a public execution path that consumed canonical context artifacts directly.
- Later execution needed to remain fail-closed, deterministic, and repair-first.

**Decision**
- Add implement feature as a strict extension of the context system.
- Reuse context validation and readiness signals as the execution gate.
- Allow only bounded, deterministic repair operations before execution when repair is explicitly requested.

**Reasoning**
- Canonical context must remain authoritative once feature execution begins.
- Reusing doctor, alignment, inspect, and verify preserves consistency and avoids a second execution policy path.
- Bounded repair keeps execution deterministic while still unblocking simple context issues.

**Alternatives Considered**
- Execute from ad hoc prompts only.
- Bypass context enforcement during implementation.
- Allow speculative context rewriting during auto-repair.

**Impact**
- Foundry can now execute feature work from canonical context artifacts.
- Feature execution updates state and decisions, then revalidates context before finishing.
- CI and scripted workflows can consume deterministic blocked, repaired, and completed results from the same context contract.

**Spec Reference**
- Constraints
- Expected Behavior
- Acceptance Criteria

### Decision: add spec-driven execution as a secondary entry point
Timestamp: 2026-04-10T12:00:00-04:00

**Context**
- Foundry could already execute feature work from canonical context artifacts, but it did not yet have a workflow-oriented entry point for bounded execution specs.
- Execution specs needed to remain secondary work orders rather than becoming a second source of feature truth.

**Decision**
- Add implement spec as a secondary entry point into the existing context-driven feature execution pipeline.
- Resolve execution specs deterministically from `docs/specs/<feature>/<NNN-name>.md`.
- Block execution when execution-spec instructions conflict with canonical feature truth.

**Reasoning**
- This preserves the rule that the canonical feature spec remains authoritative.
- This reuses the existing doctor, alignment, verification, repair, and execution behavior instead of creating a second policy path.
- This keeps execution-spec usage explainable and deterministic for CI and agent workflows.

**Alternatives Considered**
- Make execution specs authoritative after implementation.
- Duplicate the implement feature orchestration in a second command path.
- Allow execution specs to silently override canonical feature behavior.

**Impact**
- Foundry can now execute bounded implementation work orders without weakening canonical context authority.
- `implement spec` remains aligned with the existing blocked, repaired, or completed execution contract.

**Spec Reference**
- Goals
- Non-Goals
- Expected Behavior
- Acceptance Criteria

### Decision: derive the next execution spec from canonical feature context
Timestamp: 2026-04-10T12:15:00-04:00

**Context**
- Foundry could execute bounded work from canonical context and secondary execution specs, but it still lacked a public planning entry point.
- Future execution needed a deterministic way to create the next bounded work order without making execution specs authoritative.

**Decision**
- Add `plan feature` as a deterministic planning command.
- Derive one bounded execution spec at a time from the canonical feature spec, feature state, and decision ledger.

**Reasoning**
- Planning should reuse the existing context gate instead of inventing a separate planning policy path.
- Canonical feature context must remain authoritative even when Foundry generates secondary execution specs automatically.
- Generating one bounded execution spec at a time keeps planning explicit and reproducible instead of drifting into roadmap generation.

**Alternatives Considered**
- Leave execution-spec creation manual.
- Generate multi-step roadmaps from canonical context.
- Allow planning from prompt-only context outside the canonical files.

**Impact**
- Foundry can now generate the next bounded execution spec deterministically from canonical feature context.
- Generated execution specs remain secondary work orders that are immediately usable by `implement spec`.

**Spec Reference**
- Goals
- Constraints
- Expected Behavior
- Acceptance Criteria

### Decision: block planner output when the remaining gap is too abstract
Timestamp: 2026-04-10T13:00:00-04:00

**Context**
- The first auto-planning implementation could still produce execution specs with vague or self-referential wording when the remaining unmatched spec gap was too abstract.
- Foundry needed planner output that stays actionable and bounded rather than emitting low-value work orders.

**Decision**
- Refine `plan feature` to derive concrete planning gaps from Expected Behavior versus Current State.
- Generate differentiated purpose, scope, requested changes, and slug output only for meaningful concrete gaps.
- Block planning when the remaining gap is abstract or non-actionable.

**Reasoning**
- A blocked result is safer than generating an execution spec that looks valid but does not actually guide implementation.
- Concrete spec-state gaps are a more reliable planning input than vague high-level future statements.
- This keeps planning deterministic without weakening canonical feature authority or inventing a second planning policy path.

**Alternatives Considered**
- Keep generating execution specs from any unmatched spec or next-step text.
- Add fuzzy semantic or LLM-based planning heuristics.
- Solve low-quality plans by rewriting canonical context automatically.

**Impact**
- Planner output is now narrower, clearer, and less tautological.
- `plan feature` now fails cleanly when canonical context does not identify a meaningful next implementation step.

**Spec Reference**
- Constraints
- Expected Behavior
- Acceptance Criteria

### Decision: enforce planner determinism and reproducibility guarantees
Timestamp: 2026-04-10T13:45:00-04:00

**Context**
- Planner output quality had improved, but the system still needed strong guarantees that identical canonical inputs would always produce identical planning results.
- Non-deterministic planning would weaken CI reliability, reproducibility, and trust in generated execution specs.

**Decision**
- Normalize planning input into a stable deterministic structure before handing it to the planner.
- Guarantee identical planned or blocked results for identical canonical inputs.

**Reasoning**
- The planner must behave like a pure function of canonical context.
- Deterministic planning is required for automation, debugging, and stable downstream execution-spec generation.
- Stable ordering should preserve author-meaningful sequencing where that sequencing is itself canonical input.

**Alternatives Considered**
- Accept planner nondeterminism as harmless.
- Add retries or “best of several” planning attempts.
- Defer reproducibility hardening until later phases.

**Impact**
- Planner output is now stable across repeated runs with identical input.
- Blocked planning responses are reproducible.
- Planning is safer to use in automated workflows and tests.

**Spec Reference**
- Constraints
- Expected Behavior
- Acceptance Criteria

### Decision: render generated execution specs from a canonical stub template
Timestamp: 2026-04-10T14:15:00-04:00

**Context**
- Planner quality and determinism were in place, but generated execution specs were still assembled inline.
- Inline assembly risked structural drift between generated specs over time.

**Decision**
- Add a canonical execution-spec stub template.
- Render generated execution specs by merging planner content into the stub instead of assembling markdown structure inline.

**Reasoning**
- A single template keeps structure centralized and easier to evolve safely.
- This separates planner responsibility for content from renderer responsibility for structure.
- Canonical stub rendering reduces structural drift while preserving deterministic output.

**Alternatives Considered**
- Keep inline string assembly in the planner.
- Duplicate execution-spec structure logic across multiple planner paths.
- Introduce a full templating engine.

**Impact**
- Generated execution specs now share one canonical structure.
- Future structural changes can be made in one place without rewriting planner content logic.
- Planner content quality and structural rendering are now cleanly separated.

**Spec Reference**
- Constraints
- Expected Behavior
- Acceptance Criteria
