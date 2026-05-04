# Execution Spec: 001-require-full-suite-and-90-percent-coverage-before-completion

## Feature
- quality-enforcement

## Purpose
- Make full-test-suite execution and coverage enforcement a hard completion gate for implementation work.
- Prevent any spec implementation from being considered complete unless the entire PHPUnit suite has been run and coverage meets the required threshold.
- Force under-covered changed areas to be brought to at least 90% coverage before completion is allowed.

## Scope
- Add a deterministic verification/enforcement surface for repository-wide test-suite completion and coverage thresholds.
- Require the full PHPUnit suite to run for implementation completion.
- Require code coverage to be collected as part of that completion gate.
- Fail clearly when the global required threshold or changed-surface threshold is not met.
- Keep this focused on enforcement and reporting, not on redesigning PHPUnit itself.

## Constraints
- The full PHPUnit suite must be the required source of truth for completion.
- Coverage enforcement must be deterministic and machine-readable.
- The rule must be hard to forget and hard to bypass in normal Foundry workflows.
- Prefer line coverage as the primary enforced threshold unless the repository already has a stronger canonical metric.
- Do not treat targeted test runs as sufficient for completion.
- Do not allow “done” status while the full suite or required coverage gate has not passed.
- Keep enforcement conservative and explicit; do not infer completion from partial evidence.

## Inputs

Expect inputs such as:
- `php vendor/bin/phpunit`
- `php -d xdebug.mode=coverage vendor/bin/phpunit --coverage-text`
- repository source files
- touched/changed files for the current implementation run, if available
- Foundry implementation/stabilization workflows
- existing AGENTS.md and repository conventions

If any critical input is missing:
- fail clearly and deterministically
- do not assume coverage passed
- do not silently downgrade enforcement

## Requested Changes

### 1. Add a Hard Completion Gate

Introduce a repository-owned enforcement path that requires BOTH:

1. full PHPUnit suite execution
2. coverage execution

before an implementation can be considered complete.

At minimum, the system must make it impossible for Foundry-owned completion workflows to honestly report success unless both gates have run and passed.

Acceptable integration points include:
- a dedicated verification command
- an extension to an existing verification surface
- a shared enforcement service used by implementation/stabilization workflows

Choose the smallest explicit design that makes the rule real and hard to forget.

### 2. Require Full Test Suite Execution

The completion gate must require the full suite, not only targeted tests.

Required command baseline:

```bash
php vendor/bin/phpunit
```

Targeted PHPUnit runs may still be used during development, but they must not satisfy final completion on their own.

### 3. Require Coverage Execution

The completion gate must also require repository coverage to be collected.

Required command baseline:

```bash
php -d xdebug.mode=coverage vendor/bin/phpunit --coverage-text
```

If the repository later supports an equivalent canonical coverage command, that command may be used instead, but the enforcement contract must remain explicit and stable.

### 4. Enforce Minimum Coverage Thresholds

Enforce a minimum threshold of:

- global line coverage >= 90%
- changed/touched implementation surface line coverage >= 90%

The changed/touched surface should be computed from the implementation work where practical, using the smallest deterministic strategy already available in the repository.

If changed-surface coverage cannot be computed yet in a trustworthy deterministic way, the implementation must:
- enforce global line coverage >= 90% immediately
- clearly expose that changed-surface coverage is pending follow-up support
- but do not silently pretend it was enforced

Preferred outcome for this spec:
- implement both thresholds now if feasible
- otherwise implement global enforcement now and explicit structured reporting for changed-surface coverage status

### 5. Fail Clearly on Under-Coverage

If coverage is below threshold:
- fail deterministically
- report the failing metric(s)
- report the relevant file/class/module when available
- make clear that implementation is not complete

The system must not claim success while under-covered files remain below the required threshold.

### 6. Integrate with Implementation Workflows

Foundry-owned implementation workflows should use this enforcement so that:
- “implemented” or “stabilized” status is not reported as final if the full suite + coverage gate has not passed
- strict workflows fail
- normal workflows surface the failure clearly and do not misrepresent completion

This spec is about enforcement, not optimism.

### 7. Keep Reporting Machine-Readable

The enforcement result must be machine-readable and deterministic.

At minimum, output/reporting should include:
- whether full suite ran
- whether coverage ran
- global line coverage
- threshold required
- pass/fail result
- under-covered changed surfaces when available

### 8. Tests

Add focused coverage proving:

- full-suite execution is required for completion
- coverage execution is required for completion
- completion fails when global line coverage is below 90%
- completion fails when changed/touched surface coverage is below 90% (if implemented in this spec)
- reporting is deterministic and machine-readable
- existing workflows remain unchanged except for the new required enforcement
- all relevant tests still pass

## Non-Goals
- Do not redesign PHPUnit.
- Do not switch the repository to a different testing framework.
- Do not enforce method/class coverage as the primary required threshold in this spec unless the repository already canonically depends on that.
- Do not silently auto-write tests in this spec.
- Do not weaken the threshold because current coverage is below target.
- Do not treat advisory warnings as sufficient.

## Canonical Context
- Canonical feature spec: `docs/quality-enforcement/quality-enforcement.spec.md`
- Canonical feature state: `docs/quality-enforcement/quality-enforcement.md`
- Canonical decision ledger: `docs/quality-enforcement/quality-enforcement.decisions.md`

## Authority Rule
- Full-suite test execution and coverage enforcement are mandatory for implementation completion.
- Completion must fail when the required test/coverage gates have not passed.
- Under-covered changed surfaces must not be allowed to slip through as “done.”

## Completion Signals
- A repository-owned hard gate exists for full-suite execution + coverage.
- Global line coverage below 90% fails completion.
- Changed/touched implementation surface below 90% fails completion, or is explicitly reported as not yet supported if deterministic computation is not feasible in this spec.
- Output is deterministic and machine-readable.
- Foundry-owned completion workflows no longer allow this requirement to be forgotten.
- All tests pass.

## Post-Execution Expectations
- “Done” now always means the full suite ran and coverage passed.
- Coverage regressions become much harder to miss.
- Foundry implementation workflows become stricter and more trustworthy.
