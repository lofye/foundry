# Feature Spec: quality-enforcement

## Purpose
- Make implementation completion stricter and more trustworthy.
- Prevent Foundry-owned implementation workflows from reporting success before the repository quality gate has actually passed.

## Goals
- Add one shared deterministic quality gate for Foundry-owned implementation completion.
- Require the full PHPUnit suite before implementation completion is reported as final.
- Require coverage collection before implementation completion is reported as final.
- Enforce a minimum global line-coverage threshold of 90%.
- Report changed-surface coverage as enforced or explicitly unsupported; never silently imply that it passed.
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
- Changed-surface coverage must either be enforced deterministically or reported as not yet supported.
- The quality gate must fail closed when required evidence is missing or commands fail.

## Expected Behavior
- Existing contributor guidance about keeping affected areas at or above 90% coverage remains part of the workflow, but final completion enforcement moves into Foundry-owned implementation workflows.
- Foundry-owned implementation workflows run one shared quality gate before returning final success.
- The shared quality gate runs `php vendor/bin/phpunit` as the full-suite requirement.
- The shared quality gate runs `php -d xdebug.mode=coverage vendor/bin/phpunit --coverage-text` as the coverage requirement, or one explicitly equivalent canonical repository command if that contract changes later.
- Completion is downgraded from final success when the full suite fails, the coverage run fails, coverage cannot be parsed deterministically, or global line coverage is below 90%.
- Quality-gate output is machine-readable and includes whether the full suite ran, whether coverage ran, the required threshold, the measured global line coverage, and changed-surface coverage support status.
- Changed-surface coverage does not get reported as passed unless the repository can compute it deterministically.

## Acceptance Criteria
- A shared repository-owned quality gate exists for Foundry-owned implementation completion.
- `implement feature` and `implement spec` no longer report final success unless the quality gate passes.
- Full-suite failure blocks final completion.
- Coverage-run failure blocks final completion.
- Global line coverage below 90% blocks final completion.
- Changed-surface coverage is either enforced deterministically or reported explicitly as unsupported in machine-readable output.
- The quality-gate result is deterministic and machine-readable.
- PHPUnit coverage proves the shared gate behavior and both CLI implementation entry points.

## Assumptions
- The repository continues to use PHPUnit as the canonical test runner.
- Coverage output continues to expose a deterministic line-coverage percentage that can be parsed from the canonical command output.
- Changed-surface coverage may require a later deterministic implementation if the current repository signals are not yet sufficient.
