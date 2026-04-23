# Foundry App Agent Guide

Use this file when working inside a Foundry application repository.

---

## Execution Policies

### Reasoning Policy

Before any non-trivial work, load:

`docs/policies/codex-reasoning-policy.md`

Requirements:

- follow the Codex reasoning policy for the current task
- use the lowest reasoning level likely to succeed reliably
- start at medium for bounded deterministic work
- use high for multi-file, core-workflow, or non-trivial debugging work
- use extra high only for architecture, hard root-cause analysis, invariant discovery, or repeated-failure investigation
- if a different reasoning level is needed, stop and start a new run at that new level

### Execution Requirements

You must load `docs/policies/codex-reasoning-policy.md` before:

- meaningful implementation
- spec revision
- context repair
- stabilization
- root-cause debugging
- architecture work

Execution rules:

- keep reasoning proportional to authority, risk, and determinism
- prefer iterative escalation over defaulting to maximum reasoning
- when specs, commands, or validation rules tightly constrain the task, follow them rather than improvising
- do not treat the reasoning policy as optional guidance

---

## Command Rule

- In Foundry app repos, prefer `foundry ...`
- If your shell does not resolve current-directory executables, use `./foundry ...`
- Prefer `--json` for inspect, verify, doctor, prompt, export, and generation commands when an agent is consuming the output

## Source Of Truth

- Treat `app/features/*` as source-of-truth application behavior
- Treat `app/definitions/*` as source-of-truth definitions when that folder exists
- Treat `app/.foundry/build/*` as canonical compiled output
- Treat `.foundry/packs/installed.json` as explicit local pack activation state when packs are in use
- Treat `.foundry/cache/registry.json` as cached hosted-registry metadata when remote pack discovery is used
- Treat `.foundry/packs/*/*/*/foundry.json` as installed pack metadata, not editable app source
- Treat `app/generated/*` as generated compatibility projections
- Treat `docs/generated/*` and `docs/inspect-ui/*` as generated documentation output
- Treat `docs/policies/*` as repository execution and reasoning policy inputs
- Treat feature context documents under `docs/features/*` as the source of truth for feature intent, recorded implementation state, and reasoning history
- Treat code and tests as the source of truth for actual implementation and runtime behavior
- Treat execution specs under `docs/specs/*` as optional planning artifacts only and never authoritative once canonical feature context exists
- Do not hand-edit `app/generated/*`; regenerate instead
- Do not hand-edit installed pack files under `.foundry/packs/*`; reinstall or replace them from source instead

## Safe Edit Loop

1. Load the reasoning policy and choose the correct reasoning level for the task.
2. For meaningful feature work, read the feature spec, state document, and decision ledger before editing.
3. Inspect current feature and graph reality before changing code.
4. Edit the smallest source-of-truth files that satisfy the task.
5. Compile graph and inspect diagnostics.
6. Run focused tests first while iterating.
7. Before claiming implementation completion, run the full quality gate.
8. Refresh generated docs if source-of-truth changed.

## Guard Rails

- When a bug is encountered, create a test that fails because of that bug, then modify the non-test code so that the test passes while maintaining the intent of the original code
- Never take a shortcut such as forcing a false-positive test pass
- Test coverage of lines must be kept at or above 90%
- Implementations are not complete unless the full quality gate passes

## Recommended Command Loop

Use feature-scoped inspection and verification whenever possible:

```bash
foundry explain --json
foundry inspect feature <feature> --json
foundry context doctor --feature=<feature> --json
foundry context check-alignment --feature=<feature> --json
foundry inspect context <feature> --json
foundry verify context --feature=<feature> --json
foundry compile graph --json
foundry inspect graph --json
foundry inspect pipeline --json
foundry inspect impact --file=app/features/<feature>/feature.yaml --json
foundry verify graph --json
foundry verify pipeline --json
foundry verify contracts --json
```

Completion quality gate:

```bash
php vendor/bin/phpunit
php -d xdebug.mode=coverage vendor/bin/phpunit --coverage-text
```

Use `foundry context init <feature> --json` when canonical feature context files are missing.

Use `foundry context repair --feature=<feature> --json` when context verification fails and only safe normalization-style repair is appropriate.

## App Rules

- Keep changes feature-local unless the task is explicitly cross-cutting platform work
- Update feature tests and calling code together when contracts or schemas change
- Preserve explicit manifests, schemas, context files, and decision ledgers; avoid hidden behavior
- Use feature-local `prompts.md` and `context.manifest.json` when present to understand the feature before editing
- Do not silently diverge from a feature spec; if implementation must diverge, realign the code, update the spec and log the change, or record the divergence explicitly in the state document and decision ledger

## Testing And Quality Gate

- Focused tests may be used during development, but they are not the source of truth for final implementation completion
- The full PHPUnit suite must run before implementation completion is reported as final
- Coverage must run before implementation completion is reported as final
- The canonical completion quality gate is:

```bash
php vendor/bin/phpunit
php -d xdebug.mode=coverage vendor/bin/phpunit --coverage-text
```

- Implementations are not complete unless the quality gate passes
- Global line coverage must be at or above 90% for final implementation completion
- If the full suite fails, the coverage run fails, coverage output is missing or unparseable, or coverage is below threshold, do not report final success

## Context Anchoring (MANDATORY)

Foundry uses feature-level context anchoring for meaningful feature work.

Canonical feature context files:

- `docs/features/<feature-name>.spec.md` = intent
- `docs/features/<feature-name>.md` = current state
- `docs/features/<feature-name>.decisions.md` = reasoning history

Execution specs may exist under `docs/specs/<feature-name>/`, but they are:

- optional
- planning artifacts only
- never authoritative once canonical feature context exists

Source-of-truth hierarchy:

1. feature spec
2. feature state
3. feature decisions
4. code and tests

Execution specs are secondary work orders and never override canonical feature context.

Feature naming rules:

- use lowercase kebab-case only
- match the filename exactly
- do not use spaces, underscores, repeated dashes, or alternate spec filenames

Spec naming rules:

- execution spec identity is `(feature, id)`
- IDs are unique within a feature, not globally across the whole project
- slugs are descriptive only and are not required to be unique
- headings must mirror the filename only
- drafts live in `docs/specs/<feature>/drafts/`
- active specs live in `docs/specs/<feature>/`
- do not rename existing IDs
- do not add metadata fields like `id`, `parent`, or `status`

## Mandatory Workflow Rules

Read-before-acting rule:

- Before meaningful feature work, read the spec, state document, and decision ledger
- Do not rely on chat history as authoritative context
- Use `context doctor`, `context check-alignment`, `inspect context`, and `verify context` when context tooling is available
- Draft specs are non-executable planning artifacts.
- Agents MUST NOT implement specs from `docs/specs/<feature>/drafts/`.
- If asked to implement a draft spec, refuse and require promotion to the active spec path first.

Primary execution gate:

- `foundry verify context --feature=<feature> --json` is the primary machine-readable proceed/fail gate
- Meaningful work may proceed only when `verify context` passes
- `can_proceed=true` means meaningful work may proceed
- `can_proceed=false` means meaningful work is blocked and repair must happen first
- `requires_repair=true` means repair is the only valid next step before implementation
- If `verify context` is not run directly, the equivalent proceed condition is: doctor status is `ok` or `warning`, and alignment status is `ok` or `warning`

Refuse-to-proceed rule:

- Meaningful work must not proceed when `verify context` fails
- Meaningful work must not proceed when doctor status is `repairable` or `non_compliant`
- Meaningful work must not proceed when alignment status is `mismatch`

When context is non-compliant:

1. Stop.
2. Explain the non-compliance.
3. List the required corrective actions.
4. Repair or propose repair as the immediate next step.

Allowed recovery actions before implementation:

- run `foundry context init <feature> --json`
- run `foundry context repair --feature=<feature> --json`
- repair missing or malformed context files
- update the feature state document
- append a decision ledger entry
- update the feature spec and log the corresponding decision

Repair-first workflow:

- Repair is the only valid next step before implementation when context is invalid
- After meaningful implementation or planning work, update `Current State`, `Open Questions`, and `Next Steps` as needed
- After meaningful technical or architectural decisions, append a decision ledger entry
- If implementation diverges from the spec, either realign implementation, update the spec and log the change, or log and explain the divergence in the decision ledger and state document

Spec discipline:

- A feature spec must exist before meaningful implementation continues
- Each feature must have exactly one canonical spec file: `docs/features/<feature-name>.spec.md`
- Do not create alternate spec filenames such as `.spec.v2.md`, `.phase2.spec.md`, or `-v2.spec.md`

## Ask First

Stop and ask before:

- hand-editing generated files
- changing app-wide conventions, package dependencies, or generated scaffold structure without approval
- making a behavior choice when the requested behavior is ambiguous or conflicts with the existing feature contract
