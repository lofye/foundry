# Foundry Architecture Tools

The compiler core established a canonical semantic compiler and graph. The extensions and migrations layer established extensions, packs, migrations, codemods, and compatibility contracts. The architecture tools layer adds developer-facing capabilities that operate on the canonical graph plus the local install/runtime environment:

- `doctor` environment and architecture diagnostics
- `inspect graph` / `graph inspect` graph-derived summaries and filtered slices
- `graph visualize` stable rendered graph aliases
- `export graph` docs- and tooling-friendly graph exports
- `prompt` structured AI-assisted development context

## Rules

- Application graph remains the single source of truth for architecture analysis.
- Graph-derived diagnostics stay deterministic; environment/install checks layer on top without changing graph semantics.
- Prompt context is extracted from graph state, not ad hoc file scanning.
- Analyzer contributions come from extension-registered graph analyzers.
- Environment/install diagnostics can be extended through extension-registered doctor checks.
- Diagnostics use the existing `DiagnosticBag` shape and severity model.
- CLI outputs remain deterministic and support `--json`.

## Foundry Pro Layer

Foundry Pro is an optional layer on top of the same local graph and build artifacts used by core Foundry.

- Core Foundry remains fully usable without Pro.
- Pro features are additive and do not change compile, runtime, or verification semantics for unlicensed installs.
- Pro licensing is local-first and stored in `~/.foundry/license.json` by default.
- Pro does not require telemetry or mandatory network calls.
- Pro commands remain visible in CLI help and fail with a clear non-zero response when no valid license is present.

Current Pro command surface:

```bash
php vendor/bin/foundry pro enable <license-key>
php vendor/bin/foundry pro status --json
php vendor/bin/foundry doctor --deep --json
php vendor/bin/foundry explain <target> --json
php vendor/bin/foundry diff --json
php vendor/bin/foundry trace [<target>] --json
php vendor/bin/foundry generate "<prompt>" --feature-context --deterministic --dry-run --json
php vendor/bin/foundry generate "<prompt>" --provider=<name> --model=<name> --dry-run --json
```

`generate` is intentionally optional:

- `--deterministic` produces a reproducible plan from explicit prompt + graph inputs with no provider dependency.
- provider-backed mode loads providers from `app/platform/config/ai.php`.
- if no provider is configured, the command fails cleanly and points the user to `--deterministic`.

## Foundry Doctor

Command surface:

```bash
php vendor/bin/foundry doctor --json
php vendor/bin/foundry doctor --strict --json
php vendor/bin/foundry doctor --feature=<name> --json
php vendor/bin/foundry doctor --deep --json
```

Doctor compiles graph state, validates environment and build assumptions, runs extension-registered analyzers and doctor checks, and returns:

- runtime compatibility diagnostics
- install/layout diagnostics
- directory and build artifact integrity diagnostics
- metadata freshness diagnostics
- compile diagnostics
- doctor diagnostics
- extension compatibility and lifecycle diagnostics
- doctor check results
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

Current built-in doctor checks:

- PHP version and required extension compatibility
- install completeness (`composer.json`, autoload, CLI entrypoint, feature root)
- writable build/generated/log/tmp directories
- extension compatibility summary
- graph/build artifact integrity
- generated metadata freshness
- route/pipeline consistency

`--strict` fails on warnings and errors; default mode fails on errors only.
`--deep` adds Pro-only hotspot and graph topology diagnostics on top of the standard doctor payload.

## Graph Visualization And Export

Command surface:

```bash
php vendor/bin/foundry inspect graph --json
php vendor/bin/foundry graph inspect --workflow=posts --json
php vendor/bin/foundry graph visualize --events --format=mermaid --json
php vendor/bin/foundry inspect graph --command="POST /posts" --format=dot --json
php vendor/bin/foundry export graph --extension=core --format=json --json
```

Visualization views:

- `dependencies` (default): feature-to-feature dependency edges
- `events`: feature event emit/subscribe topology
- `routes`: request lifecycle-related route/feature/schema/query/event/job edges
- `caches`: cache invalidation topology
- `pipeline`: execution-plan, stage, guard, and interceptor topology
- `workflows`: workflow/orchestration relationships
- `extensions`: extension-to-pack and extension-to-pipeline ownership slices
- `command`: route/execution-plan focused slices for a feature or route target

Filters:

- `--feature=<feature>`
- `--extension=<extension>`
- `--pipeline-stage=<stage>` or `--pipeline=<stage>`
- `--command=<feature|METHOD /path|execution_plan:...>`
- `--event=<name>`
- `--workflow=<name>`
- `--area=<dependencies|events|routes|caches|pipeline|workflows|extensions|command>`

Formats:

- `mermaid`
- `dot`
- `json`
- `svg` (lightweight deterministic textual SVG rendering)

Stable aliases:

- `inspect graph` is the primary summary/slice surface
- `graph inspect` is a stable alias for the same payload
- `graph visualize` is a stable alias that defaults to rendered output
- `export graph` writes deterministic files under `app/.foundry/build/exports`

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

## Pro Explain, Diff, Trace, And Generate

- `explain <target>` resolves a typed selector, route signature, command name, exact node id, or deterministic alias into a canonical subject and explains it from compiled graph and projection metadata.
- `diff` compares the last compiled baseline graph against the current source state without changing core runtime requirements.
- `trace [<target>]` analyzes the local trace log and summarizes matching categories.
- `generate "<prompt>"` reuses the graph-backed prompt bundle flow and materializes an inspectable feature/workflow plan.
- `generate "<prompt>" --deterministic` is reproducible across runs because it derives its plan strictly from the prompt and compiled graph context.
- provider-backed generation is pluggable through the AI provider registry; no provider is hard-coded.
- generation compiles the graph again after writes and returns graph/contracts verification payloads so failures stay inspectable.

`explain` surface:

```bash
php vendor/bin/foundry explain publish_post --json
php vendor/bin/foundry explain publish_post --deep
php vendor/bin/foundry explain feature:publish_post --markdown
php vendor/bin/foundry explain route:POST /posts --json
php vendor/bin/foundry explain route:POST /posts --neighbors
php vendor/bin/foundry explain command:doctor --json
php vendor/bin/foundry explain event:post.created --json
php vendor/bin/foundry explain workflow:editorial --json
php vendor/bin/foundry explain auth --type=pipeline_stage --json
```

Supported subject kinds include:

- `feature`
- `route`
- `command`
- `pipeline_stage`
- `workflow`
- `event`
- `job`
- `schema`
- `extension`

`explain` output is deterministic and derived from:

- the canonical application graph
- compiled projections
- diagnostics metadata
- command metadata
- extension metadata
- docs metadata when available

Default human-readable output is intentionally story-shaped but stable:

- `Subject`
- `Summary`
- `Execution Flow`
- typed relationship sections such as `Depends On`, `Used By`, `Emits`, and `Triggers`
- integrated `Diagnostics`
- `Related Commands`
- `Related Docs`

`--deep` keeps the same overall layout and expands it with detailed execution stages and graph relationships instead of switching to a different output contract.

The JSON payload is plan-driven and suitable for docs generation, IDE tooling, and future AI integration. It currently remains experimental, but its structure is deliberate:

- `subject`
- `summary`
- `sections`
- `relationships`
- `execution_flow`
- `diagnostics`
- `related_commands`
- `related_docs`
- `metadata`

Extension and app integrations can enrich explanations by contributing deterministic `ExplainContributorInterface` sections before rendering.

## Extension Integration

The architecture tools layer introduces extension-visible graph analyzers via explicit extension API. Core registers default analyzers through `CoreCompilerExtension::graphAnalyzers()`. Additional analyzers can be introduced by custom extensions through the same interface.

## Development Loop

Recommended loop for graph-native changes:

1. Edit source-of-truth manifests/schemas/tests under `app/features/*`.
2. `php vendor/bin/foundry compile graph --json`
3. `php vendor/bin/foundry doctor --json`
4. `php vendor/bin/foundry inspect graph --event=post.created --format=mermaid --json`
5. `php vendor/bin/foundry prompt "<instruction>" --json`
6. Run verify and PHPUnit commands from suggested actions.

## Related Examples

- `examples/hello-world`
- `examples/workflow-events`
- `examples/architecture-tools`
- `docs/example-applications.md`
