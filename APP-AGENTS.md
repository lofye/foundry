# Foundry App Agent Guide

Use this file when working inside a Foundry application repository.

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
- Treat feature context documents under `docs/features/*` as the source of truth for feature intent, recorded implementation state, and reasoning history
- Treat code and tests as the source of truth for actual implementation and runtime behavior
- Do not hand-edit `app/generated/*`; regenerate instead
- Do not hand-edit installed pack files under `.foundry/packs/*`; reinstall or replace them from source instead

## Safe Edit Loop

1. For meaningful feature work, read the feature spec, state document, and decision ledger before editing.
2. Inspect current feature and graph reality before changing code.
3. Edit the smallest source-of-truth files that satisfy the task.
4. Compile graph and inspect diagnostics.
5. Verify graph, context, and contract surfaces.
6. Refresh generated docs if source-of-truth changed.
7. Run PHPUnit.

## Guard Rails

- When a bug is encountered, create a test that fails because of that bug, then modify the non-test code so that the test passes while maintaining the intent of the original code.
- Never take a shortcut such as forcing a false-positive test pass.
- Keep test coverage above 90% for all new features and existing code.

## Recommended Command Loop

Use feature-scoped inspection and verification whenever possible:

```bash
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
php vendor/bin/phpunit -c phpunit.xml.dist
```

Use `foundry context init <feature> --json` when canonical feature context files are missing.

## Context Anchoring

Foundry uses feature-level context anchoring for meaningful feature work.

Canonical feature context files:

- `docs/features/<feature-name>.spec.md`
- `docs/features/<feature-name>.md`
- `docs/features/<feature-name>.decisions.md`

Execution specs may exist under `docs/specs/<feature-name>/<NNN-name>.md`, but they are optional and are never authoritative once canonical feature context exists.

Feature naming rules:

- use lowercase kebab-case only
- match the filename exactly
- do not use spaces, underscores, repeated dashes, or alternate spec filenames

Document roles:

- spec = intended behavior
- state = current known implementation state
- decisions = append-only reasoning history
- code/tests = implementation and runtime behavior

## Mandatory Workflow Rules

Read-before-acting rule:

- Before meaningful feature work, read the spec, state document, and decision ledger.
- Do not rely on chat history as authoritative context.
- Use `context doctor`, `context check-alignment`, `inspect context`, and `verify context` when context tooling is available.

Primary execution gate:

- `foundry verify context --feature=<feature> --json` is the primary machine-readable proceed/fail gate.
- Meaningful work may proceed only when `verify context` passes.
- `can_proceed=true` means meaningful work may proceed.
- `can_proceed=false` means meaningful work is blocked and repair must happen first.
- `requires_repair=true` means repair is the only valid next step before implementation.
- If `verify context` is not run directly, the equivalent proceed condition is: doctor status is `ok` or `warning`, and alignment status is `ok` or `warning`.

Refuse-to-proceed rule:

- Meaningful work must not proceed when `verify context` fails.
- Meaningful work must not proceed when doctor status is `repairable` or `non_compliant`.
- Meaningful work must not proceed when alignment status is `mismatch`.

When context is non-compliant:

1. Stop.
2. Explain the non-compliance.
3. List the required corrective actions.
4. Repair or propose repair as the immediate next step.

Refusal semantics:

- `context doctor`, `context check-alignment`, `inspect context`, and `verify context` all expose `can_proceed` and `requires_repair`.
- Treat `can_proceed=false` as a hard refusal-to-proceed condition for meaningful implementation.

Allowed recovery actions before implementation:

- run `foundry context init <feature> --json`
- repair missing or malformed context files
- update the feature state document
- append a decision ledger entry
- update the feature spec and log the corresponding decision

Repair-first workflow:

- Repair is the only valid next step before implementation when context is invalid.
- After meaningful implementation or planning work, update `Current State`, `Open Questions`, and `Next Steps` as needed.
- After meaningful technical or architectural decisions, append a decision ledger entry.
- If implementation diverges from the spec, either realign implementation, update the spec and log the change, or log and explain the divergence in the decision ledger and state document.

Spec discipline:

- A feature spec must exist before meaningful implementation continues.
- Each feature must have exactly one canonical spec file: `docs/features/<feature-name>.spec.md`.
- Do not create alternate spec filenames such as `.spec.v2.md`, `.phase2.spec.md`, or `-v2.spec.md`.

## Ask First

Stop and ask before:

- hand-editing generated files
- changing app-wide conventions, package dependencies, or generated scaffold structure without approval
- making a behavior choice when the requested behavior is ambiguous or conflicts with the existing feature contract
