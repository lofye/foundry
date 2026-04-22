# Contributor Portal

This is the framework-repo entry point for contributors who want to understand, extend, or safely change Foundry itself.

In this repository use `php bin/foundry ...`. In generated Foundry apps use `foundry ...`.

## Start Here

- Read `docs/philosophy/foundry-philosophy.md` in the framework repo before changing architecture or contract surfaces.
- Use [How It Works](how-it-works.md) for the graph-native docs map and [Architecture Overview](architecture/architecture-overview.md) for the current top-level architecture description.
- Use [Extension Author Guide](extension-author-guide.md), [Extensions And Migrations](extensions-and-migrations.md), and [Public API Policy](public-api-policy.md) before changing extension-facing behavior.
- Use [Contributor PR Checklist](contributor-pr-checklist.md) before merge.
- Canonical authored docs live in `docs/*`; generated reference output is derived from framework metadata; the website repo renders and publishes the public docs experience.

## Architecture Overview

Foundry keeps contributor boundaries explicit: `collect -> analyze -> assemble -> render`.

- Collect: source-of-truth feature files under `app/features/*` and explain context collectors gather deterministic inputs only.
- Analyze: compiler analyze passes, graph analyzers, doctor checks, and explain analyzers interpret structured state without rendering.
- Assemble: the compiler assembles the canonical application graph, and `ExplanationPlanAssembler` merges canonical plus contributed explain sections using stable `sectionOrder`.
- Render: runtime projections, docs generators, graph visualizers, and explain renderers consume assembled outputs only. Renderers must not reach back into graph, compiler, or runtime state directly.

### Graph System

- Source of truth lives in `app/features/*`; canonical compiled output lives in `app/.foundry/build/*`.
- Read [Semantic Compiler](semantic-compiler.md), [Execution Pipeline](execution-pipeline.md), [Architecture Tools](architecture-tools.md), [Graph Overview](graph-overview.md), and [Architecture Explorer](architecture-explorer.html).
- Core framework loop in this repo:

```bash
php bin/foundry compile graph --json
php bin/foundry inspect graph --json
php bin/foundry verify graph --json
php bin/foundry verify pipeline --json
php bin/foundry verify contracts --json
```

### Explain System

- `ExplainEngineFactory` wires collectors, analyzers, the assembler, and renderers into the deterministic explain flow.
- Framework contributors add new explain context collectors under `src/Explain/Collectors/*` and wire them through `src/Explain/ExplainEngineFactory.php`.
- Extensions contribute explain sections through `Foundry\Explain\Contributors\ExplainContributorInterface` and `Foundry\Explain\Contributors\ExplainContribution`.
- `ExplanationPlanAssembler` owns section ordering and merge behavior; renderers only format the assembled plan.
- Read [Architecture Tools](architecture-tools.md) before changing `foundry explain`.

### CLI Structure

- `Foundry\Support\ApiSurfaceRegistry` is the authoritative CLI metadata source used by `help --json`, the interactive docs surfaces, and CLI surface verification.
- Use [Interactive CLI Index](cli-index.html), [Command Playground](command-playground.html), and [CLI Reference](cli-reference.md) to inspect the current contract.
- Verify CLI contract alignment with:

```bash
php bin/foundry help --json
php bin/foundry inspect cli-surface --json
php bin/foundry verify cli-surface --json
```

### Code Map

- CLI entrypoint: `src/CLI/Application.php`
- CLI metadata and stability classification: `src/Support/ApiSurfaceRegistry.php`
- Compiler and graph assembly: `src/Compiler/*`
- Runtime execution and pipeline logic: `src/Feature/*` and `src/Pipeline/*`
- Docs and interactive docs generation: `src/Documentation/*`
- Scaffold defaults and first-run experience: `src/CLI/Commands/InitAppCommand.php`, `APP-README.md`, and `APP-AGENTS.md`

## Extension Guide

### Create An Extension

1. Implement `Foundry\Compiler\Extensions\CompilerExtension` or extend `AbstractCompilerExtension`.
2. Provide descriptor metadata, framework and graph version constraints, and any pack metadata.
3. Register only explicit compiler-stage, projection, migration, analyzer, doctor, pipeline, or explain contributions. Do not hide behavior behind runtime side effects.

### Register Graph And Explain Integrations

- Register compiler passes through the explicit discovery, normalize, link, validate, enrich, analyze, and emit stages.
- Register graph analyzers through `graphAnalyzers()` and environment or architecture checks through `doctorChecks()`.
- Register pipeline stages and interceptors through `pipelineStages()` and `pipelineInterceptors()`.
- Register explain contributors through `ExplainContributorInterface`.
- If a change needs new explain context collection rather than a new contributed section, add a framework collector in `src/Explain/Collectors/*` and wire it in `ExplainEngineFactory`; that is a framework contribution, not a public extension registration hook.

### Inspect And Verify Extension State

```bash
php bin/foundry inspect extensions --json
php bin/foundry inspect extension <name> --json
php bin/foundry inspect compatibility --json
php bin/foundry verify extensions --json
php bin/foundry doctor --json
```

## Contribution Guidelines

- Keep framework changes minimal, explicit, and deterministic.
- Treat `src/*` as framework behavior, `tests/*` as expected behavior, and `docs/*` plus `README.md` as authored contract guidance.
- Do not hand-edit generated output under `app/generated/*`; fix the generator, compiler, verifier, or authored source instead.
- Preserve stable CLI names, JSON shapes, explain structure, and ordering unless the contract itself changes.
- Keep test coverage at or above 90% for affected areas.
- Foundry-owned implementation completion now also requires the full PHPUnit suite and the canonical coverage run to pass before work is reported as done.
- Follow spec discipline: update the spec or docs first for contract changes, then implement, realign examples, and verify determinism.
- Use [Contributor PR Checklist](contributor-pr-checklist.md) before merge.

## Development Workflow

Use the safe edit loop from `AGENTS.md`:

1. Inspect the relevant command, compiler pass, runtime path, or verifier before editing.
2. Make the smallest possible change in framework source files.
3. Recompile the root app if graph behavior, generated projections, or the demo app changed.
4. Run the narrowest relevant PHPUnit coverage first.
5. Run broader verification before finalizing, including the full PHPUnit suite and the canonical coverage command when implementation completion is the goal.

Recommended command loop in this repository:

```bash
php bin/foundry compile graph --json
php bin/foundry inspect graph --json
php bin/foundry verify graph --json
php bin/foundry verify pipeline --json
php bin/foundry verify contracts --json
vendor/bin/phpunit
```

When the task is smaller, prefer targeted inspection:

```bash
php bin/foundry inspect feature <feature> --json
php bin/foundry inspect context <feature> --json
php bin/foundry inspect impact --file=<path> --json
php bin/foundry doctor --feature=<feature> --json
```

## Roadmap Visibility

This portal is a contributor-facing roadmap for the framework repo, not a public commitment ledger.

### Current Priorities

- Preserve deterministic graph, explain, CLI, and docs contracts.
- Keep generated reference surfaces tied to canonical graph and CLI metadata.
- Strengthen extension and explain contribution boundaries without collapsing architecture layers.

### Upcoming Areas Of Work

- Continue reducing legacy compatibility paths when a graph-native path is already canonical and a safe migration exists.
- Keep contributor docs, generated reference pages, and interactive docs surfaces aligned as the framework evolves.
- Continue tightening inspect and verify coverage around extensions, execution pipeline, and explain behavior.

### Non-Goals

- No replacement for issue tracking, discussion systems, or community platforms.
- No duplicate public-docs publishing path in the framework repo.
- No renderer shortcuts that bypass assembled plan data.
- No non-deterministic graph or explain behavior.
