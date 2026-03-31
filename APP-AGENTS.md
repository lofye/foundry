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
- Do not hand-edit `app/generated/*`; regenerate instead
- Do not hand-edit installed pack files under `.foundry/packs/*`; reinstall or replace them from source instead

## Safe Edit Loop

1. Inspect current feature and graph reality before editing.
2. Edit the smallest source-of-truth files that satisfy the task.
3. Compile graph and inspect diagnostics.
4. Inspect impact, pipeline, and route surfaces when the change touches auth, routes, docs, or execution order.
5. Verify graph and contract surfaces.
6. Refresh generated docs if source-of-truth changed.
7. Run PHPUnit.

## Guard Rails

- When a bug is encountered, create a test that fails because of that bug, then modify the non-test code so that the test passes while maintaining the intent of the original code.
- Never take a shortcut (such as forcing a test falsely return true) to get a test to pass.
- Keep test coverage above 90% for all new features and existing code.

Recommended command loop:

In a scaffolded app repo, bare `foundry` runs the first-run orientation for the current project. `foundry explain --json` without a target explains the first feature or route deterministically.
Explain, explain diff, and generate JSON payloads include deterministic confidence scores, bands, and evidence factors; prefer them when an agent is deciding whether to proceed or ask for clarification.

```bash
foundry
foundry explain --json
foundry explain --diff --json
foundry inspect graph --json
foundry inspect pipeline --json
foundry pack search <query> --json
foundry inspect feature <feature> --json
foundry inspect context <feature> --json
foundry inspect packs --json
foundry compile graph --json
foundry inspect impact --file=app/features/<feature>/feature.yaml --json
foundry doctor --feature=<feature> --json
foundry generate docs --format=markdown --json
foundry generate inspect-ui --json
foundry verify graph --json
foundry verify pipeline --json
foundry verify contracts --json
php vendor/bin/phpunit -c phpunit.xml.dist
```

## App Rules

- Keep changes feature-local unless the task is explicitly cross-cutting platform work
- Update feature tests and calling code together when contracts or schemas change
- Preserve explicit manifests, schemas, and context files; avoid hidden behavior
- Use feature-local `prompts.md` and `context.manifest.json` when present to understand the feature before editing

## Ask First

Stop and ask before:
- hand-editing generated files
- changing app-wide conventions, package dependencies, or generated scaffold structure without approval
- making a behavior choice when the requested behavior is ambiguous or conflicts with the existing feature contract
