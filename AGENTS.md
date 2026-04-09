# Foundry Framework Contributor Guide

Use this file when working in the Foundry framework repository itself.

When a developer creates an application using Foundry, the `APP-AGENTS.md` file's contents will overwrite `AGENTS.md`, and the `APP-README.md` file's contents will overwrite `README.md`

## Philosophy

The philosophy behind the Foundry Framework is in docs/philosophy/foundry-philosophy.md
If you haven't already read it during this session, read it now, then proceed.

## Scope

This repository owns framework internals:
- runtime and compiler code in `src/*`
- CLI commands in `src/CLI/*`
- documentation in `README.md`, `docs/*`, and `examples/*`
- app scaffolding in `src/CLI/Commands/InitAppCommand.php`
- stub templates in `stubs/*`

The root `app/*` tree is a framework-owned demo and smoke app used for compile and verification flows. Within that app, `app/features/*` remains source of truth and `app/generated/*` remains generated output.

## Command Rule

- In this repository, use `php bin/foundry ...`
- In generated Foundry apps, use `foundry ...`
- Prefer `--json` for inspect, verify, doctor, prompt, export, and generation commands when an agent is consuming the output

## Source Of Truth

- Treat `src/*` as the source of truth for framework behavior
- Treat `tests/*` as the source of truth for expected framework behavior
- Treat feature context documents under `docs/features/*` as the source of truth for feature intent, recorded implementation state, and reasoning history
- Treat `src/CLI/Commands/InitAppCommand.php`, `APP-AGENTS.md`, and `APP-README.md` as the source of truth for the default app scaffold and app onboarding copy
- Treat `README.md` as the source of truth for framework-level onboarding and contributor guidance
- Treat `stubs/*` as source templates only when a generator actually reads them
- Do not hand-edit `app/generated/*`; regenerate from the source feature files
- Do not patch emitted build artifacts to make tests pass; fix the generator, compiler, verifier, or source inputs instead

## Safe Edit Loop

1. If the task is meaningful feature work, read the feature spec, state document, and decision ledger, then inspect and verify feature context before changing code.
2. Make the smallest change in framework source files.
3. If the change affects graph behavior, generated projections, or the demo app, recompile the root app.
4. Run the narrowest relevant PHPUnit coverage first.
5. Run broader verification before finalizing.

Common command loop:

```bash
php bin/foundry compile graph --json
php bin/foundry inspect graph --json
php bin/foundry verify graph --json
php bin/foundry verify pipeline --json
php bin/foundry verify contracts --json
vendor/bin/phpunit
```

Feature- or file-targeted inspection is preferred when it makes the task smaller:

```bash
php bin/foundry context init <feature> --json
php bin/foundry context doctor --feature=<feature> --json
php bin/foundry context check-alignment --feature=<feature> --json
php bin/foundry inspect feature <feature> --json
php bin/foundry inspect context <feature> --json
php bin/foundry verify context --feature=<feature> --json
php bin/foundry inspect impact --file=<path> --json
```

## Change Rules

- Keep framework changes minimal and explicit
- Preserve deterministic CLI and JSON output shapes unless the task explicitly changes them
- If you change a command, verifier, export, scaffold, or docs generator, update the corresponding tests in the same change
- If you change scaffolded app defaults, keep `APP-README.md`, `APP-AGENTS.md`, the promoted app `README.md` and `AGENTS.md` behavior, and init-app tests aligned
- If you change framework onboarding, command loops, starter guidance, or user-visible workflow docs, review `README.md`, `APP-README.md`, and `APP-AGENTS.md` together and update every file whose guidance is now stale
- If you change compiler or projection behavior, update both verification coverage and integration coverage
- Do not add app-specific policy to framework internals unless it is meant to be scaffolded into every app
- Renderers must never access graph, compiler, or runtime state directly; they consume only assembled plan data
- Preserve git history whenever possible (rename, don’t delete/recreate)

## Scaffold Doc Sync

- `APP-AGENTS.md` and `APP-README.md` live beside the framework `AGENTS.md` and `README.md` so scaffolded app guidance stays easy to inspect and update
- Generated apps must end with `AGENTS.md` and `README.md`; if scaffold promotion behavior changes, keep the promotion logic, template files, and init-app tests aligned
- When framework commands, directory conventions, starter modes, inspect/verify flows, or testing guidance change, update the framework `README.md` plus any matching guidance in `APP-AGENTS.md` and `APP-README.md` in the same change
- Do not update one of these onboarding files in isolation when the same guidance appears in the others

## Frozen Contracts

- Once a documented contract has been implemented, reviewed, and aligned with shipped examples, treat it as frozen
- Do not casually rewrite stable contract wording, examples, or user-visible behavior
- Any behavioral change must follow this order: propose the change, update the contract docs first, implement it, re-align examples, then verify determinism and tests
- Patch releases may contain bug fixes only and must not change stable contracts
- Minor releases may extend stable contracts additively, but must remain backward compatible and keep existing JSON output shapes stable
- Breaking changes to stable CLI behavior, JSON contracts, section structure, or explain semantics (including `foundry explain`) require a major-version plan
- If behavior matters to users or tooling, it must be reflected in docs, implementation, and tests
- Stable output must remain deterministic: same input must produce identical output, with no timestamps, randomness, ordering instability, or environment leakage
- The `foundry explain` output (text, JSON, markdown, and deep mode) is a versioned contract
- Its structure, section ordering, and JSON shape must remain stable across patch and minor releases
- Do not "improve", reformat, or expand examples unless required to match actual behavior

## Testing Discipline

- Every framework behavior change needs PHPUnit coverage
- Prefer focused test runs while iterating, then finish with the broader relevant suite
- Do not weaken assertions, delete failing coverage, or edit previously-passing tests or generated output to hide regressions
- When changing CLI scaffolding or textual contract surfaces, assert the generated files and key content in integration tests
- When a bug is encountered, create a test that fails because of that bug, then modify the non-test code so that the test passes while maintaining the intent of the original code.
- Keep test coverage above 90% for all new features and existing code.

## Ask First

Stop and ask before:
- changing package names, Composer constraints, or public command names without explicit direction
- making breaking changes to scaffolded app structure or generated file conventions
- changing verification semantics in ways that could invalidate existing apps without a migration path
- making a behavior choice when the existing docs, tests, and code disagree

## SPEC DISCIPLINE RULE

Specs are contracts, not drafts.

If a spec has been implemented and aligned:
- Do NOT modify it casually
- Do NOT change examples unless implementation has changed

If behavior needs to change:
1. update the spec first
2. implement the change
3. realign examples
4. verify tests + determinism

Never let docs drift from implementation.
Never let implementation drift from spec.

Determinism and contract stability are required.
If a change would surprise a user or break tooling, it is a contract change.

## Docs

### Source of truth

•	The framework/ submodule is the canonical source of framework documentation.
•	In the framework repo, everything under docs/ is authored canonical content unless explicitly marked otherwise.
•	The website repo is the presentation layer and may contain:
•	content/docs/authored/ for website-only docs
•	content/docs/imported/ for docs synced from framework/docs/
•	public/docs/ for generated output
•	The website repo is the only canonical renderer/publisher of public docs and version snapshots.
•	Do not create duplicate canonical framework docs in content/docs/authored/.
•	Do not edit imported docs manually.
•	Website HTML pages in public/*.html belong to the website repo, not the framework repo.
•	Do not use `scripts/build-docs.php`, `DocsSiteBuilder`, or `docs/versions/` as the primary publishing path; they are deprecated framework-local preview helpers only.
•	Before moving or deleting docs, audit which docs are actually used by the build pipeline.

### Docs mental model
•	framework/docs/ = truth
•	framework/docs/versions/ = deprecated legacy preview snapshot input, not published source of record
•	website/content/docs/imported/ = synced copy
•	website/content/docs/authored/ = website-only authored docs
•	website/public/docs/ = generated output

## Context Anchoring

Foundry uses feature-level context anchoring for meaningful feature work.

Canonical feature context files:

- `docs/features/<feature-name>.spec.md`
- `docs/features/<feature-name>.md`
- `docs/features/<feature-name>.decisions.md`

Execution specs may exist under `docs/specs/<feature-name>/<NNN-name>.md`, but they are optional and are never authoritative once a feature has canonical context files.

Feature naming rules:

- use lowercase kebab-case only
- match the filename exactly
- do not use spaces, underscores, repeated dashes, or alternative spec filenames

Source-of-truth boundaries:

- spec = intended behavior
- state = current known implementation state
- decisions = append-only reasoning history
- code/tests = implementation and runtime behavior

Read-before-acting rule:

- Before meaningful feature work, read the spec, state document, and decision ledger.
- Do not rely on chat history as authoritative context.
- Use the context tools that match the task: `context doctor`, `context check-alignment`, `inspect context`, and `verify context`.

Primary execution gate:

- `php bin/foundry verify context --feature=<feature> --json` is the primary machine-readable proceed/fail gate.
- Meaningful work may proceed only when `verify context` passes.
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

Allowed recovery actions before implementation:

- run `php bin/foundry context init <feature> --json`
- repair missing or malformed context files
- update the feature state document
- append a decision ledger entry
- update the feature spec and log the corresponding decision

Repair-first workflow:

- Repair is the only valid next step before implementation when context is invalid.
- After meaningful implementation or planning work, update the feature state document.
- After meaningful technical or architectural decisions, append a decision ledger entry.
- If implementation diverges from the spec, either realign implementation, update the spec and log the change, or log and explain the divergence in the decision ledger and state document.

Spec evolution rule:

- Each feature must have exactly one canonical spec file: `docs/features/<feature-name>.spec.md`.
- Do not create alternate spec filenames such as `.spec.v2.md`, `.phase2.spec.md`, or `-v2.spec.md`.
- When a feature evolves, update the canonical spec, log the change in the decision ledger, and continue implementation against the updated spec.
