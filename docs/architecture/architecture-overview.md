# Foundry Architecture Overview

Foundry is a compile-first PHP framework. Authored feature and definition files become a canonical application graph, and runtime, verification, docs, and inspect surfaces read from that compiled state instead of relying on ad hoc runtime discovery.

## Source-Of-Truth Boundaries

- `app/features/*` is the primary authored source of truth for application behavior.
- `app/definitions/*` adds authored definitions for workflows, resources, inspect UI, and other capability-specific inputs when those capabilities are used.
- `app/.foundry/build/*` is canonical compiled output: graph artifacts, projections, manifests, diagnostics, and exports.
- `app/generated/*` is a generated compatibility mirror for runtime and tooling that still expect legacy projection paths.
- `src/*` contains the framework implementation.
- `docs/*` in this repository is canonical authored framework documentation.
- The website repository imports framework docs and is the only canonical renderer and publisher of public docs and version snapshots.

## Runtime Shape

The normal application loop is:

1. Author or change source-of-truth files.
2. Run `compile graph` to build the canonical graph, projections, manifests, and diagnostics.
3. Run `inspect`, `doctor`, and `verify` commands against compiled state.
4. Serve requests through compiled execution plans and generated indexes.

Foundry does not rely on hot-path folder scanning for request execution. For a typical HTTP feature the runtime path is:

1. match the route from generated indexes
2. resolve the feature and compiled execution plan
3. run guards, validation, and any configured pipeline behavior
4. execute the feature action
5. validate output and emit the response

When compatibility fallbacks exist, they remain deterministic and compile-shaped rather than dynamic or hidden.

## Contributor Mental Model

Foundry keeps contributor boundaries explicit: `collect -> analyze -> assemble -> render`.

- Collect: feature files, definitions, configuration, and explain collectors gather deterministic inputs only.
- Analyze: compiler passes, graph analyzers, doctor checks, and explain analyzers interpret structured state without rendering.
- Assemble: the compiler assembles the canonical graph, and explain assemblers merge canonical plus contributed sections using stable ordering.
- Render: runtime projections, docs generators, graph visualizers, inspect UI output, and explain renderers consume assembled data only.

Renderers must not reach back into compiler, graph, or runtime state directly.

## Core Subsystems

- CLI discovery and classification: `src/CLI/Application.php` and `src/Support/ApiSurfaceRegistry.php`.
- Compiler and graph assembly: `src/Compiler/*`.
- Runtime execution and pipeline behavior: `src/Feature/*`, `src/Pipeline/*`, and related runtime services.
- Verification: graph, pipeline, contract, compatibility, and capability verifiers.
- Documentation and explain generation: `src/Documentation/*` and `src/Explain/*`.
- App scaffolding: `src/CLI/Commands/InitAppCommand.php` plus scaffold templates.
- Extensions, packs, migrations, and codemods: explicit extension registries and migration tooling.

Platform capability areas remain explicit and inspectable. Current storage support is local-first and in-memory only; unfinished object-storage adapters are not part of the current framework contract.

## CLI And Verification Story

The CLI surface is intentionally classified:

- `stable`: semver-governed command names, options, and JSON shapes
- `experimental`: usable but still allowed to evolve in minor releases
- `internal`: framework/developer workflows with no compatibility guarantee

Use these discovery entry points first:

```bash
php bin/foundry help
php bin/foundry help inspect
php bin/foundry help verify
php bin/foundry help generate
php bin/foundry inspect cli-surface --json
php bin/foundry verify cli-surface --json
```

The release-critical core loop remains:

```bash
php bin/foundry compile graph --json
php bin/foundry inspect graph --json
php bin/foundry verify graph --json
php bin/foundry verify pipeline --json
php bin/foundry verify contracts --json
```

## Determinism And Safety Rules

- Same authored inputs must produce the same graph, projections, and JSON output.
- Generated artifacts are explicit files, not opaque archives or hidden caches.
- `app/generated/*` and other emitted artifacts are compiled output, not handwritten source.
- Feature actions execute through explicit contracts; there is no hidden runtime DI magic in the feature action boundary.
- Inspect, verify, help, export, and generation surfaces support machine-readable JSON output where the framework exposes them as contract surfaces.
- Public docs are authored in the framework repo and published from the website repo; the framework repo is not the public docs publishing pipeline.
