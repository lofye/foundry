# Feature: execution-spec-system

## Purpose
- Define and enforce canonical execution-spec naming, identity, heading, validation, and draft-creation rules.

## Current State
- Active execution specs live at `docs/specs/<feature>/<id>-<slug>.md`.
- Draft execution specs live at `docs/specs/<feature>/drafts/<id>-<slug>.md`.
- Execution-spec ids use one or more dot-separated 3-digit numeric segments such as `001` and `015.002.001`.
- Execution spec headings use `# Execution Spec: <id>-<slug>` and match the filename only.
- `ExecutionSpecResolver` resolves active execution specs with canonical hierarchical ids and may still accept a unique filename shorthand.
- Resolver validation rejects noncanonical filenames and noncanonical headings.
- `plan feature` allocates the next root id without colliding with existing active or draft spec ids, including hierarchical descendants.
- Existing spec files in the repository use the canonical filename-only heading format.
- Hierarchical padded execution-spec filenames are accepted and parsed deterministically.
- Parent ids can be derived from filename segments.
- Generated execution specs use filename-only headings.
- Noncanonical headings and filenames fail clearly.
- Planner allocation stays deterministic and avoids active or draft id collisions.
- Framework docs and tests reflect the canonical execution-spec naming system.
- PHPUnit coverage covers hierarchical resolution and planner allocation behavior.
- `spec:new <feature> "<slug>"` creates draft execution specs under `docs/specs/<feature>/drafts/<id>-<slug>.md`.
- `spec:new` normalizes slug input to lowercase kebab-case and rejects empty or low-information results.
- `spec:new` creates the required draft template with a filename-only heading and does not modify existing specs.
- `spec:new` fails clearly when feature input is invalid, the target path already exists, or allocation cannot proceed deterministically.
- `spec:new` emits stable success and failure output for terminals and automation.
- `spec:new` writes one file on success and no files on failure.
- `spec:validate` scans active and draft execution specs under `docs/specs/` without modifying files.
- `spec:validate` reports invalid filenames, invalid placement, duplicate ids, incorrect headings, and forbidden `id`, `parent`, or `status` metadata deterministically.
- `spec:validate` exits with status `0` when spec state is valid and non-zero when any violations exist.
- JSON and terminal output for `spec:validate` expose all detected violations for automation and manual repair.
- PHPUnit coverage covers the execution-spec validation service and CLI command.

## Open Questions
- When should Foundry support explicit child-spec allocation instead of only root allocation?
- Should CLI or inspect tools expose execution-spec trees directly?
- Should draft promotion validate parent existence explicitly?

## Next Steps
- Add automatic implementation-log support after the draft-creation and validation flows are stable.
- Introduce child-spec allocation when multi-level planning becomes a concrete requirement.
