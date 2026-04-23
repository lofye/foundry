# Feature Spec: quality-enforcement

## Purpose
- Make implementation completion stricter and more trustworthy.
- Prevent Foundry-owned implementation workflows from reporting success before the repository quality gate has actually passed.

## Goals
- Add one shared deterministic quality gate for Foundry-owned implementation completion.
- Require the full PHPUnit suite before implementation completion is reported as final.
- Require coverage collection before implementation completion is reported as final.
- Enforce a minimum global line-coverage threshold of 90%.
- Enforce changed-surface line coverage deterministically at or above 90% for changed PHP source files under enforcement.
- Keep the enforcement output machine-readable so strict and normal workflows can report the result honestly.

## Non-Goals
- Do not redesign PHPUnit itself.
- Do not replace targeted test runs during development.
- Do not weaken the threshold because current coverage may be below target.
- Do not silently auto-write missing tests.

## Constraints
- The full PHPUnit suite is the completion source of truth rather than targeted tests alone.
- Coverage collection must be explicit and deterministic.
- The enforcement path must be hard to forget in Foundry-owned implementation workflows.
- Global line coverage must fail completion when it is below 90%.
- Changed-surface coverage must be enforced deterministically for changed PHP source files under enforcement.
- Changed-surface detection must prefer workflow-owned touched-file evidence when available and use repository-owned changed-file detection as the fallback.
- Changed-surface enforcement scope must exclude docs, generated internals, vendor content, storage artifacts, stubs, and nested test paths.
- The quality gate must fail closed when required evidence is missing or commands fail.

## Expected Behavior
- Existing contributor guidance about keeping affected areas at or above 90% coverage remains part of the workflow, but final completion enforcement moves into Foundry-owned implementation workflows.
- Foundry-owned implementation workflows run one shared quality gate before returning final success.
- The shared quality gate runs `php vendor/bin/phpunit` as the full-suite requirement.
- The shared quality gate runs `php -d xdebug.mode=coverage vendor/bin/phpunit --coverage-text --coverage-clover storage/tmp/foundry-quality-gate-clover.xml` as the canonical coverage requirement.
- Completion is downgraded from final success when the full suite fails, the coverage run fails, coverage cannot be parsed deterministically, global line coverage is below 90%, changed files cannot be determined deterministically, or any enforced changed PHP source file is below 90% line coverage.
- Quality-gate output is machine-readable and includes whether the full suite ran, whether coverage ran, the required threshold, the measured global line coverage, changed files examined, per-file changed-surface coverage, under-covered changed files, and changed-surface pass/fail status.
- Changed-surface coverage is enforced deterministically rather than being reported as unsupported.
- The changed-surface gate prefers the touched-file set recorded by Foundry-owned implementation workflows and falls back to repository-owned changed-file detection only when explicit workflow-touched files are unavailable.
- Changed-surface enforcement applies only to changed PHP source files under enforcement and ignores docs-only changes, generated internals, vendor content, storage artifacts, stubs, and nested test paths.
- Changed-surface attribution failure blocks final completion rather than degrading to advisory output.

## Acceptance Criteria
- A shared repository-owned quality gate exists for Foundry-owned implementation completion.
- `implement feature` and `implement spec` no longer report final success unless the quality gate passes.
- Full-suite failure blocks final completion.
- Coverage-run failure blocks final completion.
- Global line coverage below 90% blocks final completion.
- Changed-surface coverage below 90% for any enforced changed PHP source file blocks final completion.
- Changed-surface attribution failure blocks final completion.
- Workflow-owned touched-file evidence is used when available, with repository-owned changed-file detection as the deterministic fallback.
- The quality-gate result is deterministic and machine-readable.
- PHPUnit coverage proves the shared gate behavior and both CLI implementation entry points.

## Assumptions
- The repository continues to use PHPUnit as the canonical test runner.
- Coverage output continues to expose a deterministic global line-coverage summary and a deterministic per-file Clover report from the canonical command output.
- Changed-surface enforcement applies to changed PHP source files under repository-owned enforcement rules rather than to docs-only or generated-internal artifacts.
