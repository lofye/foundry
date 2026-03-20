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
- In generated Foundry apps, use `php vendor/bin/foundry ...`
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
