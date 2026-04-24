## 2026-04-07 11:02:41 -0400
- spec: context-persistence/001-initial.md

## 2026-04-08 15:38:05 -0400
- spec: context-persistence/002-context-init-and-doctor.md

## 2026-04-09 10:23:29 -0400
- spec: context-persistence/003-spec-state-alignment-engine.md

## 2026-04-09 11:23:27 -0400
- spec: context-persistence/004-alignment-noise-reduction.md

## 2026-04-09 11:50:23 -0400
- spec: context-persistence/005-inspect-context-integration-and-verification-wiring.md

## 2026-04-09 12:03:27 -0400
- spec: context-persistence/006-context-persistence-reconciliation.md

## 2026-04-09 12:53:00 -0400
- spec: context-persistence/007-agents-app-agents-scaffold-and-onboarding-integration.md

## 2026-04-09 13:14:29 -0400
- spec: context-persistence/008-refuse-to-proceed-hardening-and-compliance-finalization.md

## 2026-04-09 15:16:56 -0400
- spec: context-persistence/009-context-driven-feature-execution.md

## 2026-04-10 10:00:28 -0400
- spec: context-persistence/010-spec-driven-execution-entry-point.md

## 2026-04-10 10:50:06 -0400
- spec: context-persistence/011-auto-planning-from-canonical-feature-context.md

## 2026-04-10 13:36:56 -0400
- spec: canonical-identifiers/001-accept-normalized-input-and-canonicalize-visibly.md

## 2026-04-10 13:36:56 -0400
- spec: context-persistence/012-planner-output-quality-refinement.md

## 2026-04-10 13:45:24 -0400
- spec: context-persistence/013-planner-determinism-reproducibility-guarantees.md

## 2026-04-10 16:04:30 -0400
- spec: context-persistence/014-use-stub-templates-for-generated-specs.md

## 2026-04-10 16:38:33 -0400
- spec: context-persistence/015-state-normalization-pass-and-canonical-ordering.md

## 2026-04-14 10:07:03 -0400
- spec: execution-spec-system/001-hierarchical-spec-ids-with-padded-segments.md
- note: Implemented hierarchical padded execution-spec ids, filename-only headings, and draft-aware root allocation.

## 2026-04-14 13:43:51 -0400
- spec: execution-spec-system/002-spec-new-cli-command.md
- note: Added spec:new with deterministic draft allocation, normalized slug handling, and stable plain-text success and failure output.

## 2026-04-14 15:22:44 -0400
- spec: execution-spec-system/003-spec-validate-command.md
- note: Added spec:validate with deterministic repository-wide rule checks for placement, headings, IDs, and forbidden metadata.

## 2026-04-14 23:36:55 -0400
- spec: execution-spec-system/004-spec-auto-log-on-implementation.md
- note: Added automatic active-spec implementation logging with idempotent append behavior and clear write-failure reporting.

## 2026-04-15 00:56:26 -0400
- spec: execution-spec-system/005-fix-canonical-conflict-detection.md
- note: Narrowed canonical conflict detection so aligned execution specs are not blocked by topic-word overlap while true forbidden-action contradictions still fail deterministically.

## 2026-04-15 09:19:45 -0400
- spec: execution-spec-system/006-prevent-framework-spec-implementation-from-scaffolding-app-features.md
- note: Blocked framework-repository execution specs before generic app-feature scaffolding and removed the accidental execution-spec-system app scaffold output.

## 2026-04-15 09:58:40 -0400
- spec: context-persistence/015.001-context-doctor-execution-spec-drift.md
- note: Added execution-spec drift detection to context doctor and verify context without changing their existing issue contracts.

## 2026-04-15 10:13:23 -0400
- spec: context-persistence/015.002-context-doctor-diagnostic-rule-structure.md
- note: Introduced a normalized internal doctor-rule model and centralized doctor-to-verify flattening while preserving existing output contracts.

## 2026-04-15 13:31:49 -0400
- spec: context-persistence/015.003-generalize-state-normalization-rules.md
- note: Added a reusable state-document normalizer and integrated it into the framework-owned state update path for deterministic section ordering and conservative stale-bullet cleanup.

## 2026-04-15 15:10:00 -0400
- spec: context-persistence/016-planner-generic-fallback-blocking-and-slug-hardening.md
- note: Blocked generic fallback planner output, removed the `initial` slug fallback, and tightened bounded completion-signal and content-quality gates before execution specs are written.

## 2026-04-15 16:05:00 -0400
- spec: context-persistence/017-conflict-detection-prohibition-awareness.md
- note: Made canonical execution-spec conflict detection polarity-aware, preserved nested negative lead-in context, and tightened blocking to true opposing-polarity contradictions with substantially similar target actions.

## 2026-04-16 09:48:21 -0400
- spec: context-persistence/018-cli-spec-invocation-improvements.md
- note: Added deterministic `implement spec <feature> <id>` resolution for active specs, kept existing full-ref and unique filename shorthand behavior, and surfaced clear shorthand failure modes for malformed, draft-only, ambiguous, unknown-id, and unknown-feature targets.

## 2026-04-16 13:23:23 -0400
- spec: context-persistence/018.001-plan-feature-writes-one-draft-spec-deterministically.md
- note: Changed `plan feature` to write exactly one verified draft execution spec per successful invocation, report the exact written path truthfully, and require promotion before planned specs can be executed.

## 2026-04-17 09:26:23 -0400
- spec: context-persistence/018.002-repair-cli-experience-context-alignment.md
- note: Repaired `cli-experience` canonical context alignment by restating the current verified CLI surface in spec-matching language, removing an unrelated shorthand-ergonomics state claim, and tracking pending completion work explicitly in `Next Steps`.

## 2026-04-17 09:50:44 -0400
- spec: cli-experience/001-cli-autocomplete.md
- note: Added a stable `completion` command that emits bash and zsh scripts, derives static command completion from the CLI registry, and completes active execution-spec ids for `implement spec` without including drafts by default.

## 2026-04-17 09:55:16 -0400
- spec: cli-experience/002-repair-cli-experience-context-alignment.md
- note: Repaired `cli-experience` canonical context alignment by restating the current verified CLI surface in spec-matching language, removing an unsupported shorthand-ergonomics claim, and tracking remaining completion work explicitly in `Next Steps`.

## 2026-04-17 10:00:00 -0400
- spec: context-persistence/019-fails-when-doctor-repairable.md
- note: Formalized the already-implemented verify-context readiness behavior that fails when doctor status is `repairable` or `non_compliant`, promoted the execution spec to active, and recorded the completed step.

## 2026-04-17 11:15:10 -0400
- spec: compiler-determinism/001-freeze-time-for-deterministic-graph-compilation.md

## 2026-04-17 11:36:46 -0400
- spec: context-persistence/020-keep-later-execution-systems-safely-consumable.md

## 2026-04-17 12:04:09 -0400
- spec: context-persistence/020.001-require-implementation-log-entry-for-completed-active-specs.md
- note: Extended deterministic execution-spec validation to require exact implementation-log coverage for active specs while continuing to exempt drafts.

## 2026-04-17 13:33:01 -0400
- spec: context-persistence/020.002-suggest-exact-implementation-log-entry-content.md
- note: Added `spec:log-entry` to emit the exact canonical implementation-log entry content for an active execution spec and reject draft-only or invalid targets clearly.

## 2026-04-17 16:00:58 -0400
- spec: context-persistence/021-revalidates-context-after-execution.md
- note: Extracted the final post-execution context revalidation into one explicit execution-finalization path and added regression coverage that downgrades the result when canonical context is invalid after execution updates.

## 2026-04-20 13:37:14 -0400
- spec: context-persistence/022-expand-context-diagnostic-rules.md
- note: Added exactly two high-signal doctor diagnostics, `STALE_COMPLETED_ITEMS_IN_NEXT_STEPS` and `DECISION_MISSING_FOR_STATE_DIVERGENCE`, while preserving the existing doctor and verify-context output contracts.

## 2026-04-21 10:05:58 -0400
- spec: context-persistence/023-normalize-additional-context-artifacts.md
- note: Extended reusable context normalization to canonical feature spec documents on framework-owned spec write paths while preserving meaning, deterministic ordering, and existing state-document normalization behavior.

## 2026-04-21 13:20:07 -0400
- spec: context-persistence/024-add-context-auto-repair-mode.md
- note: Added an explicit `context repair` command that reuses existing inspect and verify analysis, applies only safe normalization-style repairs to canonical spec/state files, and fails closed when critical context inputs are missing or manual semantic repair is still required.

## 2026-04-21 13:33:30 -0400
- spec: context-persistence/025-rule-deduplication-and-issue-coalescing.md

## 2026-04-22 02:51:48 +0000
- spec: generate-engine/001-interactive-generate-plan-review.md
- note: Added interactive generate review with summary/detail/diff preview, approval or rejection, minimal plan filtering, risk confirmation, and filtered-plan execution that reuses the existing validation and verification pipeline.
- note: Added deterministic post-processing that coalesces overlapping doctor-rule results, deduplicates exact issue rows, and reuses canonical required-action coalescing across doctor and verify outputs without changing their JSON shapes.

## 2026-04-22 03:21:49 +0000
- spec: quality-enforcement/001-require-full-suite-and-90-percent-coverage-before-completion.md
- note: Added a shared implementation quality gate that requires the full PHPUnit suite, the canonical coverage run, and global line coverage at or above 90% before `implement feature` or `implement spec` can report final success.
- note: Exposed deterministic machine-readable `quality_gate` output on implementation payloads and reported changed-surface coverage explicitly as not yet supported instead of implying enforcement that does not exist yet.

## 2026-04-22 13:30:22 +0000
- spec: generate-engine/001.001-valid-interactive-generate-smoke-invocation.md
- note: Added an explicit non-destructive interactive generate smoke integration test that uses a valid `--mode=new --interactive` command shape, reaches the review layer, records rejection, and avoids filesystem mutation.
- note: Updated the earlier interactive-generate execution-spec example so repository docs no longer suggest a mode-less interactive invocation that would fail in CLI validation.

## 2026-04-22 14:20:00 +0000
- spec: generate-engine/002-generate-skill-integration.md
- note: Added deterministic `safety_routing` recommendations to generate payloads and history so agent integrations can route between fast non-interactive execution and interactive review without reimplementing framework risk logic.
- note: Added unit coverage for low-risk, elevated-risk, explicit interactive override, and CI-context routing behavior, plus integration coverage that exercises the emitted routing payload on real generate flows and aligns generate-engine docs with the new skill-contract surface.

## 2026-04-22 22:45:47 -0400
- spec: quality-enforcement/001.001-enforce-changed-surface-90-percent-coverage.md
- note: Extended the shared implementation quality gate to derive deterministic changed-file sets from workflow-touched files or repository state, emit a Clover report during the canonical coverage run, and enforce 90% changed-surface line coverage for changed PHP source files under enforcement.
- note: Added machine-readable changed-surface reporting for changed files examined, per-file coverage, under-covered files, and attribution failures, and removed the old unsupported changed-surface status.

## 2026-04-23 13:53:20 +0000
- spec: generate-engine/003-plan-persistence-and-history.md

## 2026-04-23 13:53:20 +0000
- spec: quality-enforcement/001.002-eliminate-phpunit-warning-and-risky-state-blockers.md

## 2026-04-23 10:11:10 -0400
- spec: generate-engine/003.001-plan-replay.md
- note: Added `plan:replay` with adaptive, strict, and dry-run modes that rehydrate persisted plan artifacts, reuse generate validation and execution seams, and surface drift explicitly instead of silently regenerating new plans.
- note: Added replay-focused integration and command-surface coverage proving stored-plan execution by id, strict drift failure, adaptive drift reporting, and dry-run no-write behavior.

## 2026-04-23 12:29:29 -0400
- spec: generate-engine/003.002-plan-undo.md
- note: Added `plan:undo` with conservative dry-run and live rollback modes, explicit `--yes` gating for destructive generated-file deletion, and deterministic reporting of reversible, irreversible, and unsafe-skipped actions.
- note: Extended successful persisted plan artifacts with minimal pre-change file snapshots so V1 undo can restore updated or deleted files only when rollback inputs are explicitly available, and added focused undo integration plus unit coverage.

## 2026-04-23 16:59:57 -0400
- spec: generate-engine/004-plan-snapshot-and-patch-based-undo.md
- note: Upgraded persisted generate plan rollback data to store before/after snapshots, unified-diff patches, and integrity hashes so undo can use structured rollback inputs instead of snapshot-only best effort.
- note: Extended `plan:undo` to select snapshot or patch rollback deterministically, surface `rollback_mode`, `reversible`, `files_recovered`, `files_unrecoverable`, `integrity_warnings`, and `confidence_level`, and added focused patch, deleted-file, integrity-mismatch, and determinism coverage.

## 2026-04-23 23:12:43 -0400
- spec: generate-engine/005-generate-policies.md
- note: Implemented repository-local generate policy system with deterministic V1 evaluation (deny, warn, require, limit) against validated plans.
- note: Integrated policy evaluation into GenerateEngine and CLI, including `--policy-check` and explicit violation override flows.
- note: Added interactive policy confirmation and persisted policy results in plan history and plan-record artifacts.
- note: Updated generate-engine feature docs and decision ledger.
- note: Added focused unit and integration coverage.
- note: Resolved test warnings/deprecations to satisfy strict coverage gate (now exits 0 at 90.02% line coverage).