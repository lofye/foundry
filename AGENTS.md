# Foundry Framework Contributor Guide

Use this file when working in the Foundry framework repository itself.

For generated Foundry application repos, use the scaffolded app-level `AGENTS.md`, not this file.

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
- Treat `src/CLI/Commands/InitAppCommand.php` as the source of truth for the default app scaffold
- Treat `stubs/*` as source templates only when a generator actually reads them
- Do not hand-edit `app/generated/*`; regenerate from the source feature files
- Do not patch emitted build artifacts to make tests pass; fix the generator, compiler, verifier, or source inputs instead

## Safe Edit Loop

1. Inspect the relevant command, compiler pass, runtime path, or verifier before changing code.
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
php bin/foundry inspect feature <feature> --json
php bin/foundry inspect context <feature> --json
php bin/foundry inspect impact --file=<path> --json
php bin/foundry doctor --feature=<feature> --json
```

## Change Rules

- Keep framework changes minimal and explicit
- Preserve deterministic CLI and JSON output shapes unless the task explicitly changes them
- If you change a command, verifier, export, scaffold, or docs generator, update the corresponding tests in the same change
- If you change scaffolded app defaults, keep the scaffolded `README.md`, scaffolded `AGENTS.md`, and init-app tests aligned
- If you change compiler or projection behavior, update both verification coverage and integration coverage
- Do not add app-specific policy to framework internals unless it is meant to be scaffolded into every app
- Renderers must never access graph, compiler, or runtime state directly; they consume only assembled plan data

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