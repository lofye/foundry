# Foundry Semantic Compiler Phase 0C

Phase 0A established a canonical semantic compiler and graph. Phase 0B established extensions, packs, migrations, codemods, and compatibility contracts. Phase 0C adds developer-facing capabilities that operate strictly on the canonical graph:

- `doctor` architecture diagnostics
- `graph visualize` graph-derived architecture diagrams
- `prompt` structured AI-assisted development context

## Phase 0C Rules

- Application graph remains the single source of truth.
- Analysis and visualization derive only from graph nodes/edges.
- Prompt context is extracted from graph state, not ad hoc file scanning.
- Analyzer contributions come from extension-registered graph analyzers.
- Diagnostics use the existing `DiagnosticBag` shape and severity model.
- CLI outputs remain deterministic and support `--json`.

## Foundry Doctor

Command surface:

```bash
php vendor/bin/foundry doctor --json
php vendor/bin/foundry doctor --strict --json
php vendor/bin/foundry doctor --feature=<name> --json
```

Doctor compiles graph state, runs extension-registered analyzers, and returns:

- compile diagnostics
- doctor diagnostics
- analyzer-specific findings
- optional feature-scoped impact preview
- deterministic recommended follow-up commands

Current analyzer set (core extension):

- dependency cycle detection
- auth coverage analysis
- schema/query integrity heuristics
- dead code detection
- cache topology checks
- test coverage checks

`--strict` fails on warnings and errors; default mode fails on errors only.

## Graph Visualization

Command surface:

```bash
php vendor/bin/foundry graph visualize --json
php vendor/bin/foundry graph visualize --events --format=mermaid --json
php vendor/bin/foundry graph visualize --routes --format=dot --json
php vendor/bin/foundry graph visualize --caches --feature=<name> --format=svg --json
```

Visualization views:

- `dependencies` (default): feature-to-feature dependency edges
- `events`: feature event emit/subscribe topology
- `routes`: request lifecycle-related route/feature/schema/query/event/job edges
- `caches`: cache invalidation topology

Formats:

- `mermaid`
- `dot`
- `json`
- `svg` (lightweight deterministic textual SVG rendering)

## Foundry Prompt

Command surface:

```bash
php vendor/bin/foundry prompt "<instruction>" --json
php vendor/bin/foundry prompt "<instruction>" --feature-context --dry-run --json
```

Prompt flow:

1. Compile graph.
2. Extract relevant context bundle from graph nodes/edges.
3. Build deterministic structured prompt text and constraints.
4. Provide preflight compile/verify diagnostics and correction template.
5. Return recommended verification/test commands.

Context extraction prioritizes feature matches by instruction tokens, route paths, events, cache keys, and permissions. If no match exists, deterministic fallback selects a bounded feature subset.

## Extension Integration

Phase 0C introduces extension-visible graph analyzers via explicit extension API. Core registers default analyzers through `CoreCompilerExtension::graphAnalyzers()`. Additional analyzers can be introduced by custom extensions through the same interface.

## Development Loop

Recommended loop for graph-native changes:

1. Edit source-of-truth manifests/schemas/tests under `app/features/*`.
2. `php vendor/bin/foundry compile graph --json`
3. `php vendor/bin/foundry doctor --json`
4. `php vendor/bin/foundry graph visualize --events --format=mermaid --json`
5. `php vendor/bin/foundry prompt "<instruction>" --json`
6. Run verify and PHPUnit commands from suggested actions.

