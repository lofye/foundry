# Feature: quality-enforcement

## Purpose
- Make implementation completion stricter and more trustworthy.

## Current State
- Existing contributor guidance about keeping affected areas at or above 90% coverage remains part of the workflow, but final completion enforcement moves into Foundry-owned implementation workflows.
- A shared repository-owned quality gate exists for Foundry-owned implementation completion.
- Foundry-owned implementation workflows run one shared quality gate before returning final success.
- The shared quality gate runs `php vendor/bin/phpunit` as the full-suite requirement.
- The shared quality gate runs `php -d xdebug.mode=coverage vendor/bin/phpunit --coverage-text` as the coverage requirement.
- `implement feature` and `implement spec` now downgrade completion when the full PHPUnit suite fails, the coverage run fails, coverage cannot be parsed deterministically, or global line coverage is below 90%.
- `implement feature` and `implement spec` no longer report final success unless the quality gate passes.
- Full-suite failure blocks final completion.
- Coverage-run failure blocks final completion.
- Implementation-completion payloads now expose machine-readable `quality_gate` reporting with full-suite status, coverage status, global line coverage, threshold, and changed-surface support status.
- The quality-gate result is deterministic and machine-readable.
- Global line coverage is now enforced at or above 90% for Foundry-owned implementation completion.
- Global line coverage below 90% blocks final completion.
- Changed-surface coverage is reported explicitly as not yet supported by the deterministic repository gate rather than being implied or guessed.
- Changed-surface coverage is reported explicitly as unsupported in machine-readable output.
- PHPUnit coverage proves the shared gate behavior and both CLI implementation entry points.

## Open Questions
- What is the smallest deterministic repository signal that can support changed-surface coverage enforcement safely?

## Next Steps
- Add deterministic changed-surface coverage enforcement when the repository has a trustworthy signal for touched implementation surface.
- Keep contributor docs and workflow guidance aligned with the hard completion gate contract.
