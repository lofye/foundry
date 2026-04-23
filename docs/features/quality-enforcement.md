# Feature: quality-enforcement

## Purpose
- Make implementation completion stricter and more trustworthy.

## Current State
- Existing contributor guidance about keeping affected areas at or above 90% coverage remains part of the workflow, but final completion enforcement moves into Foundry-owned implementation workflows.
- A shared repository-owned quality gate exists for Foundry-owned implementation completion.
- Foundry-owned implementation workflows run one shared quality gate before returning final success.
- The shared quality gate runs `php vendor/bin/phpunit` as the full-suite requirement.
- The shared quality gate runs `php -d xdebug.mode=coverage vendor/bin/phpunit --coverage-text --coverage-clover storage/tmp/foundry-quality-gate-clover.xml` as the canonical coverage requirement.
- `implement feature` and `implement spec` now downgrade completion when the full PHPUnit suite fails, the coverage run fails, coverage cannot be parsed deterministically, global line coverage is below 90%, changed files cannot be determined deterministically, or any enforced changed PHP source file is below 90% line coverage.
- `implement feature` and `implement spec` no longer report final success unless the quality gate passes.
- Full-suite failure blocks final completion.
- Coverage-run failure blocks final completion.
- The repository strict PHPUnit baseline is now clean under the existing configuration: the stale init-app scaffold assertion, risky human-mode first-run tests, and warning-producing cleanup/stub-loading paths were fixed at the source without weakening PHPUnit strictness.
- Strict PHPUnit warning and risky enforcement remain enabled, and pre-existing blockers are fixed at their real source instead of being hidden by relaxed PHPUnit rules.
- `php vendor/bin/phpunit` now exits `0` under the repository's strict warning and risky settings.
- `php -d xdebug.mode=coverage vendor/bin/phpunit --coverage-text` still exits successfully after the strict-baseline cleanup.
- Implementation-completion payloads now expose machine-readable `quality_gate` reporting with full-suite status, coverage status, global line coverage, threshold, changed files examined, per-file changed-surface coverage, under-covered changed files, and changed-surface pass/fail status.
- The quality-gate result is deterministic and machine-readable.
- Global line coverage is now enforced at or above 90% for Foundry-owned implementation completion.
- Global line coverage below 90% blocks final completion.
- Changed-surface enforcement now derives the smallest deterministic touched-file set from the implementation workflow when available and falls back to repository-owned changed-file detection when needed.
- Changed-surface coverage is now enforced at or above 90% for changed PHP source files under enforcement.
- Changed-surface enforcement now ignores docs-only changes, generated internals, vendor content, storage artifacts, stubs, and nested test paths.
- Changed-surface attribution failure now blocks final completion instead of degrading to an unsupported status.
- PHPUnit coverage proves the shared gate behavior and both CLI implementation entry points.

## Open Questions
- Should future quality enforcement broaden beyond changed PHP source files under enforcement to additional deterministic source surfaces such as templates or non-PHP runtime artifacts?

## Next Steps
- Keep contributor docs and workflow guidance aligned with the hard completion gate contract.
- Decide whether additional deterministic source-surface categories should participate in changed-surface enforcement beyond the current PHP scope.
