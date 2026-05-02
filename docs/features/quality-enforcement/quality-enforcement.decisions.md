### Decision: establish quality-enforcement as a standalone feature
Timestamp: 2026-04-21T10:15:00-04:00

**Context**
- Repository contributor guidance already asked humans to keep affected areas at or above 90% coverage.
- Foundry-owned implementation workflows still had no shared hard completion gate for full-suite execution and coverage collection.
- The requested execution spec defines a cross-cutting enforcement rule that affects implementation workflows rather than one isolated command.

**Decision**
- Create a standalone `quality-enforcement` feature with its own canonical spec, state document, and decision ledger.
- Treat implementation-completion quality gating as feature-owned behavior instead of leaving it as documentation-only guidance.

**Reasoning**
- The enforcement rule spans CLI workflows, testing commands, machine-readable reporting, and repository discipline.
- A dedicated feature keeps the quality-gate contract explicit and gives future follow-up work, such as changed-surface coverage enforcement, a clear canonical home.

**Alternatives Considered**
- Leave the rule implicit in contributor documentation only.
- Fold the work into an unrelated context or execution-spec feature.

**Impact**
- Quality enforcement now has a canonical context anchor before runtime changes land.
- Future changes to completion gating can build on one explicit source of truth.

**Spec Reference**
- Purpose
- Goals
- Constraints

### Decision: record staged spec-state divergence while the hard gate is being introduced
Timestamp: 2026-04-21T10:20:00-04:00

**Context**
- The canonical spec for `quality-enforcement` now describes the intended completion gate behavior for this feature.
- The repository state still reflects the pre-implementation reality: contributor guidance exists, but Foundry-owned implementation workflows do not yet enforce the full-suite plus coverage gate.
- Context verification requires intentional divergence between target spec behavior and current state to be logged explicitly.

**Decision**
- Keep the canonical spec focused on the intended hard-gate behavior for this feature.
- Record the current pre-implementation gap in the state document and support that gap with an explicit decision entry until the implementation lands.

**Reasoning**
- The spec should preserve the feature contract we are actively implementing rather than restating the old soft-guidance-only reality.
- The state document still needs to remain truthful about the current repository behavior while the work is in flight.
- Logging the divergence keeps the context consumable without pretending the feature is already complete.

**Alternatives Considered**
- Rewrite the spec to describe only the current soft guidance.
- Pretend the hard gate is already implemented in the state document.

**Impact**
- Context verification can distinguish intentional staged rollout from accidental drift.
- The canonical context stays truthful while implementation work is still pending.

**Spec Reference**
- Expected Behavior
- Acceptance Criteria

### Decision: enforce quality through one shared execution-finalization gate and defer changed-surface coverage
Timestamp: 2026-04-21T11:05:00-04:00

**Context**
- The requested spec required a hard completion gate for Foundry-owned implementation workflows, explicit full-suite and coverage execution, a 90% threshold, and machine-readable reporting.
- `implement feature` and `implement spec` already converged in `ContextExecutionService::finalizeExecutionResult()`, which was the narrowest place to block final success without duplicating policy.
- The repository did not yet expose a trustworthy deterministic touched-surface coverage signal suitable for hard enforcement in this step.

**Decision**
- Introduce one shared `ImplementationQualityGateService` and run it from `ContextExecutionService::finalizeExecutionResult()` after post-execution context revalidation.
- Require both `php vendor/bin/phpunit` and `php -d xdebug.mode=coverage vendor/bin/phpunit --coverage-text` before final success is returned.
- Enforce global line coverage at or above 90%.
- Report changed-surface coverage explicitly as not yet supported instead of pretending it was enforced.

**Reasoning**
- The shared execution-finalization path makes the rule hard to forget in Foundry-owned implementation workflows while keeping the change small.
- Running the gate after context revalidation preserves the existing execution contract and avoids claiming success when canonical context is already broken.
- Failing closed on unparseable or failed coverage output keeps the enforcement deterministic.
- Explicitly deferring changed-surface enforcement is safer than shipping a brittle heuristic and calling it authoritative.

**Alternatives Considered**
- Add a separate verification command and leave implementation workflows unchanged.
- Enforce only targeted tests or advisory warnings.
- Attempt changed-surface coverage immediately with a speculative heuristic.

**Impact**
- `implement feature` and `implement spec` now return final success only when the shared quality gate passes.
- Implementation payloads now expose machine-readable quality-gate results.
- Global coverage enforcement is real immediately, while changed-surface support remains a clearly visible follow-up item.

**Spec Reference**
- Constraints
- Expected Behavior
- Acceptance Criteria

### Decision: enforce changed-surface coverage through workflow-touched files plus Clover attribution
Timestamp: 2026-04-22T22:45:48-04:00

**Context**
- The initial quality-enforcement rollout deliberately deferred changed-surface enforcement and reported that gap explicitly as unsupported.
- The active execution spec `quality-enforcement/001.001-enforce-changed-surface-90-percent-coverage.md` now requires that unsupported status to be removed and replaced with deterministic enforcement.
- Foundry-owned implementation workflows already record the files they touch, while the repository also has a Git inspector that can derive changed files when workflow-owned file lists are unavailable.
- The existing coverage gate already runs one canonical PHPUnit coverage command, but changed-surface attribution needs per-file data rather than only the global line summary.

**Decision**
- Extend `ImplementationQualityGateService` to enforce changed-surface coverage for changed PHP source files under enforcement.
- Prefer the deterministic touched-file set derived from Foundry-owned workflow actions, and fall back to repository-owned changed-file detection only when no explicit touched-file list is available.
- Extend the canonical coverage command to also emit a deterministic Clover report at `storage/tmp/foundry-quality-gate-clover.xml` during the same coverage run.
- Fail closed when changed files cannot be determined deterministically, when Clover attribution is missing for an enforced changed file, or when any enforced changed file is below the 90% threshold.
- Exclude docs, generated artifacts, vendor content, storage artifacts, stubs, and nested `tests/` paths from changed-surface enforcement.

**Reasoning**
- Reusing workflow-touched files is the narrowest and most trustworthy signal for Foundry-owned implementation completion because it avoids conflating unrelated dirty-worktree files with the current run.
- Git inspection remains a deterministic repository-owned fallback when explicit workflow file lists are unavailable.
- Clover attribution adds the minimum extra machine-readable surface needed for per-file line coverage without introducing a second disconnected enforcement flow.
- Failing closed preserves the core quality-enforcement principle that implementation completion must not be reported as final when required evidence is missing.
- Excluding non-runtime or non-enforced surfaces keeps the gate focused on changed implementation code rather than docs or generated internals.

**Alternatives Considered**
- Continue reporting changed-surface coverage as unsupported.
- Enforce changed-surface coverage from the current dirty working tree alone, even when the workflow already knows which files it touched.
- Introduce a second standalone changed-surface verification command instead of extending the existing shared quality gate.
- Attempt heuristic attribution from text coverage output alone without machine-readable per-file coverage data.

**Impact**
- `implement feature` and `implement spec` now require full-suite, global, and changed-surface coverage evidence before reporting final success.
- Quality-gate payloads now report deterministic changed-file sets, per-file changed-surface coverage, and under-covered changed files.
- Unsupported changed-surface reporting is removed in favor of real enforcement or explicit hard failure when attribution cannot be trusted.

**Spec Reference**
- Goals
- Constraints
- Expected Behavior
- Acceptance Criteria

### Decision: remove strict PHPUnit blocker debt at the source instead of weakening enforcement
Timestamp: 2026-04-22T23:35:00-04:00

**Context**
- The `quality-enforcement/001.002-eliminate-phpunit-warning-and-risky-state-blockers.md` execution spec required the repository to regain a genuinely clean strict PHPUnit baseline.
- The repository still had one stale scaffold assertion plus strict-mode blockers caused by risky human-mode test output and warning-prone cleanup/stub-loading paths.
- The quality-enforcement feature depends on `php vendor/bin/phpunit` and the coverage command being trustworthy hard gates under the existing strict PHPUnit configuration.

**Decision**
- Keep the existing strict PHPUnit configuration unchanged.
- Fix the stale init-app scaffold assertion in test code.
- Fix the risky first-run tests by asserting the interactive output they intentionally trigger.
- Fix framework cleanup and stub-loading paths so missing files and already-removed directories are handled explicitly rather than relying on warning suppression.

**Reasoning**
- Relaxing warning or risky enforcement would make the quality gate less trustworthy precisely where this feature is supposed to be strict.
- The smallest durable fix is to make tests honest about expected output and make filesystem cleanup and idempotence explicit in framework code.
- Clearing the baseline blocker debt lets future strict-gate failures represent real regressions instead of inherited suite noise.

**Alternatives Considered**
- Disable or weaken strict PHPUnit warning and risky handling.
- Leave warning suppression in place and treat the suite as "good enough."
- Patch only the visible stale assertion and ignore the strict-mode blocker sites named in the execution spec.

**Impact**
- `php vendor/bin/phpunit` now exits cleanly under the repository's strict warning and risky settings.
- The coverage gate remains compatible with the strict-baseline cleanup.
- Quality-enforcement can now rely on the repository test suite as an honest completion signal.

**Spec Reference**
- Constraints
- Requested Changes
- Completion Signals
