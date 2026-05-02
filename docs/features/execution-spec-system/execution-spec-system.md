# Feature: execution-spec-system

## Purpose

- Define and enforce canonical execution-spec naming, identity, heading, validation, draft-creation, implementation-log, conflict-detection, and framework-repository safety rules.

## Current State

- Active execution specs live at `docs/features/<feature>/specs/<id>-<slug>.md`, and drafts live under `docs/features/<feature>/specs/drafts/<id>-<slug>.md`.
- Execution-spec ids use one or more dot-separated 3-digit numeric segments such as `001` and `015.002.001`.
- `implement spec` resolves active execution specs deterministically from canonical full refs, exact `<feature> <id>` shorthand within a feature, and unique active filename shorthand.
- `<feature> <id>` resolution matches canonical hierarchical ids exactly and fails clearly for malformed, draft-only, ambiguous, or unknown shorthand targets.
- Execution spec headings use `# Execution Spec: <id>-<slug>` and match the filename only.
- Resolver validation rejects noncanonical filenames and noncanonical headings.
- Existing spec files in the repository use the canonical filename-only heading format.
- Hierarchical padded execution-spec filenames are accepted and parsed deterministically.
- Parent ids can be derived from filename segments.
- Generated execution specs use filename-only headings.
- Noncanonical headings and filenames fail clearly.
- `plan feature` allocates the next root id without colliding with existing active or draft spec ids, including hierarchical descendants.
- Planner allocation stays deterministic and avoids active or draft id collisions.
- Framework docs and tests reflect the canonical execution-spec naming system.
- PHPUnit coverage covers hierarchical resolution and planner allocation behavior.
- `spec:new <feature> "<slug>"` creates draft execution specs under `docs/features/<feature>/specs/drafts/<id>-<slug>.md`.
- `spec:new` normalizes slug input to lowercase kebab-case and rejects empty or low-information results.
- `spec:new` creates the required draft template with a filename-only heading and does not modify existing specs.
- `spec:new` fails clearly when feature input is invalid, the target path already exists, or allocation cannot proceed deterministically.
- `spec:new` emits stable success and failure output for terminals and automation.
- `spec:new` writes one file on success and no files on failure.
- `spec:log-entry` now resolves active execution specs through the canonical resolver and emits the exact implementation-log entry content expected by validation.
- `spec:log-entry` returns machine-readable canonical spec ref, canonical spec path, exact `- spec:` line, and full entry content for one active spec.
- `spec:log-entry` fails clearly for draft-only, malformed, or unknown targets and does not suggest implementation-log entries for drafts.
- `spec:validate` scans active and draft execution specs under `docs/features/` without modifying files.
- `spec:validate` reports invalid filenames, invalid placement, duplicate ids, incorrect headings, and forbidden `id`, `parent`, or `status` metadata deterministically.
- `spec:validate` now also requires exact implementation-log coverage for active execution specs, ignores drafts, and reports missing coverage deterministically.
- `spec:validate` exits with status `0` when spec state is valid and non-zero when any violations exist.
- `spec:validate` returns both terminal output and JSON payloads that include every detected violation for repair workflows and automation.
- PHPUnit coverage covers the execution-spec validation service and CLI command behavior.
- Successful `implement spec` runs for active execution specs append exactly one required-format entry to `docs/features/implementation-log.md`.
- Auto-logging skips draft execution-spec paths and does not duplicate existing implementation-log entries for the same active spec.
- Implementation-log write failures surface as `completed_with_issues` instead of a clean successful spec-completion result.
- Successful active execution-spec implementation appends exactly one correctly formatted implementation-log entry automatically.
- Draft execution specs are not auto-logged.
- Implementation-log write failures surface clearly and deterministically and do not appear as a clean successful completion.
- Canonical conflict detection now compares positive execution-spec instructions against forbidden clauses extracted from canonical non-goals and negative constraints instead of blocking on topic-word overlap alone.
- Execution specs that reinforce canonical behavior without instructing a forbidden action are not blocked by canonical conflict detection.
- True contradictions against canonical non-goals or negative constraints still return `EXECUTION_SPEC_CONFLICTS_WITH_CANONICAL_SPEC` deterministically.
- In the framework repository, `implement spec` now blocks framework-internal execution specs before the generic `app/features/*` scaffold path.
- Framework-repository execution specs do not create `app/features/<feature>/` scaffolds for framework-internal features such as `execution-spec-system`.
- `implement spec` returns a deterministic explicit block instead of silently generating misplaced app-feature output in the framework repository.
- Agent-facing framework and app instruction files now consistently describe canonical feature context as `docs/features/<feature>/<feature>.*`.
- Agent-facing instruction surfaces now include canonical draft-spec, plan, and implementation-log paths: `docs/features/<feature>/specs/drafts/*.md`, `docs/features/<feature>/plans/*.md`, and `docs/features/implementation-log.md`.
- Repository-local implementation skills now reference canonical spec and implementation-log paths under `docs/features/*` instead of legacy `docs/specs/*`.
- Agent-facing framework, app, and skill instruction surfaces reflect only the canonical feature-doc path contract, and stale-path references are retained only in explicitly historical or migration contexts.

## Open Questions

- When should Foundry support explicit child-spec allocation instead of only root allocation?
- Should CLI or inspect tools expose execution-spec trees directly?
- Should draft promotion validate parent existence explicitly?
- What dedicated framework-internal implementation path should eventually replace the current explicit framework-repository block?

## Next Steps

- Introduce child-spec allocation when multi-level planning becomes a concrete requirement.
- Define a dedicated framework-internal execution path for `implement spec` so framework features can eventually be executed without routing into generic app scaffolding.
