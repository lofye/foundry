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
foundry pro enable <license-key>
foundry pro status --json
foundry doctor --deep --json
foundry explain <target> --json
foundry diff --json
foundry trace [<target>] --json
foundry generate "<prompt>" --feature-context --deterministic --dry-run --json
foundry generate "<prompt>" --provider=<name> --model=<name> --dry-run --json
```

`generate` is intentionally optional:

- `--deterministic` produces a reproducible plan from explicit prompt + graph inputs with no provider dependency.
- provider-backed mode loads providers from `config/ai.php`.
- if no provider is configured, the command fails cleanly and points the user to `--deterministic`.

## Foundry Doctor

Command surface:

```bash
foundry doctor --json
foundry doctor --strict --json
foundry doctor --feature=<name> --json
foundry doctor --deep --json
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
foundry inspect graph --json
foundry graph inspect --workflow=posts --json
foundry graph visualize --events --format=mermaid --json
foundry inspect graph --command="POST /posts" --format=dot --json
foundry export graph --extension=core --format=json --json
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
foundry prompt "<instruction>" --json
foundry prompt "<instruction>" --feature-context --dry-run --json
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
foundry explain publish_post --json
foundry explain publish_post --deep
foundry explain feature:publish_post --markdown
foundry explain route:POST /posts --json
foundry explain route:POST /posts --neighbors
foundry explain command:doctor --json
foundry explain event:post.created --json
foundry explain workflow:editorial --json
foundry explain auth --type=pipeline_stage --json
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
- `Responsibilities`
- `Execution Flow`
- `Depends On`
- `Used By`
- `Emits`
- `Triggers`
- `Permissions`
- `Schema Interaction`
- `Graph Relationships`
- `Related Commands`
- `Related Docs`
- integrated `Diagnostics`
- `Suggested Fixes`

`--deep` keeps the same overall layout and expands it with detailed execution stages and graph relationships instead of switching to a different output contract.

Representative default text output:

```text
Subject
  POST /posts
  kind: route

Summary
  POST /posts handles requests through the compiled application graph. It dispatches the publish_post feature action. It emits post.created. It triggers notify_followers.

Responsibilities
  Handle POST /posts requests
  Dispatch the publish_post feature action

Execution Flow
  request
  -> auth guard
  -> permission guard (posts.create)
  -> rate_limit guard
  -> request_validation guard
  -> transaction guard
  -> publish_post feature action
  -> post.created
  -> notify_followers

Depends On
  feature:publish_post
  permission:posts.create
  schema:app/features/publish_post/input.schema.json
  schema:app/features/publish_post/output.schema.json

Emits
  event:post.created

Triggers
  job:notify_followers

Permissions
  posts.create
    defined in: feature:publish_post
    enforced by: guard:permission:publish_post:posts_create @ auth

Schema Interaction
  reads: schema:app/features/publish_post/input.schema.json
  writes: schema:app/features/publish_post/output.schema.json

Graph Relationships
  inbound: feature:publish_post

Related Commands
  php bin/foundry inspect execution-plan POST /posts --json
  php bin/foundry inspect graph --command=POST /posts --json
  php bin/foundry inspect node route:POST:/posts --json
  php bin/foundry inspect pipeline --json
  php bin/foundry inspect route POST /posts --json
  foundry verify feature publish_post --json
  foundry verify graph --json
  foundry verify pipeline --json
  php vendor/bin/phpunit

Related Docs
  docs/architecture-tools.md
  docs/execution-pipeline.md
  docs/how-it-works.md
  docs/reference.md

Diagnostics
  INFO Event has no subscribers: post.created

Suggested Fixes
  - Add a subscriber or workflow for event: post.created

Impact
  risk: high
  affected_features: publish_post
  affected_routes: POST /posts
  affected_events:
  affected_jobs:
  affected_projections: execution_plan_index.php, feature_index.php, routes_index.php
```

Representative `--deep` output:

```text
Subject
  POST /posts
  kind: route

Summary
  POST /posts handles requests through the compiled application graph. It dispatches the publish_post feature action. It emits post.created. It triggers notify_followers.

Responsibilities
  Handle POST /posts requests
  Dispatch the publish_post feature action

Execution Flow (Detailed)
  Stage 1: request
  Stage 2: auth guard
    - stage: auth
  Stage 3: permission guard (posts.create)
    - stage: auth
    - required: posts.create
  Stage 4: rate_limit guard
    - stage: before_auth
  Stage 5: request_validation guard
    - stage: validation
  Stage 6: transaction guard
    - stage: before_action
  Stage 7: publish_post feature action
    - feature: publish_post
  Stage 8: post.created
  Stage 9: notify_followers

Depends On
  feature:publish_post
  permission:posts.create
  schema:app/features/publish_post/input.schema.json
  schema:app/features/publish_post/output.schema.json

Emits
  event:post.created

Triggers
  job:notify_followers

Permissions
  posts.create
    defined in: feature:publish_post
    enforced by: guard:permission:publish_post:posts_create @ auth

Schema Interaction
  reads: schema:app/features/publish_post/input.schema.json
  writes: schema:app/features/publish_post/output.schema.json

Graph Relationships (Expanded)
  inbound: feature:publish_post

Related Commands
  php bin/foundry inspect execution-plan POST /posts --json
  php bin/foundry inspect graph --command=POST /posts --json
  php bin/foundry inspect node route:POST:/posts --json
  php bin/foundry inspect pipeline --json
  php bin/foundry inspect route POST /posts --json
  foundry verify feature publish_post --json
  foundry verify graph --json
  foundry verify pipeline --json
  php vendor/bin/phpunit

Related Docs
  docs/architecture-tools.md
  docs/execution-pipeline.md
  docs/how-it-works.md
  docs/reference.md

Diagnostics
  INFO Event has no subscribers: post.created

Suggested Fixes
  - Add a subscriber or workflow for event: post.created

Impact
  risk: high
  affected_features: publish_post
  affected_routes: POST /posts
  affected_events:
  affected_jobs:
  affected_projections: execution_plan_index.php, feature_index.php, routes_index.php
```

The JSON payload is plan-driven and suitable for docs generation, IDE tooling, and future AI integration. It currently remains experimental, but its structure is deliberate:

- `subject`
- `summary`
- `relationships`
- `executionFlow`
- `responsibilities`
- `emits`
- `triggers`
- `permissions`
- `schemaInteraction`
- `diagnostics`
- `relatedCommands`
- `relatedDocs`
- `suggestedFixes`
- `sections`
- `sectionOrder`
- `metadata`

Representative `--json` output:

```json
{
  "subject": {
    "id": "route:POST:/posts",
    "kind": "route",
    "label": "POST /posts",
    "graph_node_ids": ["route:POST:/posts"],
    "aliases": ["POST /posts", "route:POST:/posts"],
    "metadata": {
      "method": "POST",
      "path": "/posts",
      "signature": "POST /posts",
      "feature": "publish_post"
    }
  },
  "summary": {
    "text": "POST /posts handles requests through the compiled application graph. It dispatches the publish_post feature action. It emits post.created. It triggers notify_followers.",
    "deterministic": true,
    "deep": false
  },
  "responsibilities": {
    "items": [
      "Handle POST /posts requests",
      "Dispatch the publish_post feature action"
    ]
  },
  "executionFlow": {
    "entries": [
      { "kind": "request", "label": "request" },
      { "kind": "guard", "label": "auth guard" },
      { "kind": "guard", "label": "permission guard (posts.create)" },
      { "kind": "guard", "label": "rate_limit guard" },
      { "kind": "guard", "label": "request_validation guard" },
      { "kind": "guard", "label": "transaction guard" },
      { "kind": "action", "label": "publish_post feature action" },
      { "kind": "event", "label": "post.created", "name": "post.created" },
      { "kind": "job", "label": "notify_followers", "name": "notify_followers" }
    ],
    "stages": [
      { "id": "pipeline_stage:request_received", "kind": "pipeline_stage", "label": "request_received", "name": "request_received", "order": 0 },
      { "id": "pipeline_stage:auth", "kind": "pipeline_stage", "label": "auth", "name": "auth", "order": 3 },
      { "id": "pipeline_stage:action", "kind": "pipeline_stage", "label": "action", "name": "action", "order": 7 }
    ],
    "guards": [
      { "id": "guard:auth:publish_post", "feature": "publish_post", "type": "authentication", "stage": "auth" },
      { "id": "guard:permission:publish_post:posts_create", "feature": "publish_post", "type": "permission", "stage": "auth" }
    ],
    "action": {
      "id": "feature:publish_post",
      "kind": "feature",
      "label": "publish_post",
      "feature": "publish_post"
    },
    "events": [
      { "id": "event:post.created", "kind": "event", "label": "post.created", "name": "post.created" }
    ],
    "workflows": [],
    "jobs": [
      { "id": "job:notify_followers", "kind": "job", "label": "notify_followers", "name": "notify_followers" }
    ]
  },
  "relationships": {
    "dependsOn": {
      "items": [
        { "id": "feature:publish_post", "kind": "feature", "label": "publish_post", "feature": "publish_post" },
        { "id": "permission:posts.create", "kind": "permission", "label": "posts.create" },
        { "id": "schema:app/features/publish_post/input.schema.json", "kind": "schema", "label": "app/features/publish_post/input.schema.json", "path": "app/features/publish_post/input.schema.json", "role": "input", "feature": "publish_post" },
        { "id": "schema:app/features/publish_post/output.schema.json", "kind": "schema", "label": "app/features/publish_post/output.schema.json", "path": "app/features/publish_post/output.schema.json", "role": "output", "feature": "publish_post" }
      ]
    },
    "usedBy": {
      "items": []
    },
    "graph": {
      "inbound": [
        { "id": "feature:publish_post", "kind": "feature", "label": "publish_post", "feature": "publish_post", "source_path": "app/features/publish_post/feature.yaml", "edge_type": "feature_to_route" }
      ],
      "outbound": [],
      "lateral": []
    }
  },
  "emits": {
    "items": [
      { "id": "event:post.created", "kind": "event", "label": "post.created", "name": "post.created" }
    ]
  },
  "triggers": {
    "items": [
      { "id": "job:notify_followers", "kind": "job", "label": "notify_followers" }
    ]
  },
  "permissions": {
    "required": ["posts.create"],
    "enforced_by": [
      { "guard": "guard:permission:publish_post:posts_create", "permission": "posts.create", "stage": "auth" }
    ],
    "defined_in": [
      { "permission": "posts.create", "source": "feature:publish_post" }
    ],
    "missing": []
  },
  "schemaInteraction": {
    "items": [
      { "id": "schema:app/features/publish_post/input.schema.json", "kind": "schema", "label": "app/features/publish_post/input.schema.json", "path": "app/features/publish_post/input.schema.json", "role": "input", "feature": "publish_post" },
      { "id": "schema:app/features/publish_post/output.schema.json", "kind": "schema", "label": "app/features/publish_post/output.schema.json", "path": "app/features/publish_post/output.schema.json", "role": "output", "feature": "publish_post" }
    ],
    "reads": [
      { "id": "schema:app/features/publish_post/input.schema.json", "kind": "schema", "label": "app/features/publish_post/input.schema.json", "path": "app/features/publish_post/input.schema.json", "role": "input", "feature": "publish_post" }
    ],
    "writes": [
      { "id": "schema:app/features/publish_post/output.schema.json", "kind": "schema", "label": "app/features/publish_post/output.schema.json", "path": "app/features/publish_post/output.schema.json", "role": "output", "feature": "publish_post" }
    ],
    "fields": [],
    "subject": null
  },
  "relatedCommands": [
    "php bin/foundry inspect execution-plan POST /posts --json",
    "php bin/foundry inspect graph --command=POST /posts --json",
    "php bin/foundry inspect node route:POST:/posts --json",
    "php bin/foundry inspect pipeline --json",
    "php bin/foundry inspect route POST /posts --json",
    "foundry verify feature publish_post --json",
    "foundry verify graph --json",
    "foundry verify pipeline --json",
    "php vendor/bin/phpunit"
  ],
  "relatedDocs": [
    { "id": "architecture-tools", "title": "Architecture Tools", "path": "docs/architecture-tools.md", "source": "docs" },
    { "id": "execution-pipeline", "title": "Execution Pipeline", "path": "docs/execution-pipeline.md", "source": "docs" },
    { "id": "how-it-works", "title": "How It Works", "path": "docs/how-it-works.md", "source": "docs" },
    { "id": "reference", "title": "Reference", "path": "docs/reference.md", "source": "docs" }
  ],
  "diagnostics": {
    "summary": { "error": 0, "warning": 0, "info": 1, "total": 1 },
    "items": [
      {
        "id": "D0002",
        "code": "FDY1302_EVENT_NO_SUBSCRIBERS",
        "severity": "info",
        "category": "events",
        "message": "Event has no subscribers: post.created",
        "node_id": "event:post.created",
        "source_path": "app/features/publish_post/events.yaml",
        "source_line": null,
        "related_nodes": [],
        "suggested_fix": null,
        "pass": "validate",
        "why_it_matters": null,
        "details": []
      }
    ]
  },
  "suggestedFixes": [
    "Add a subscriber or workflow for event: post.created"
  ],
  "sections": [
    {
      "id": "impact",
      "title": "Impact",
      "shape": "key_value",
      "items": {
        "risk": "high",
        "affected_features": ["publish_post"],
        "affected_routes": ["POST /posts"],
        "affected_events": [],
        "affected_jobs": [],
        "affected_projections": ["execution_plan_index.php", "feature_index.php", "routes_index.php"]
      }
    }
  ],
  "sectionOrder": [
    "subject",
    "summary",
    "responsibilities",
    "execution_flow",
    "dependencies",
    "emits",
    "triggers",
    "permissions",
    "schema_interaction",
    "graph_relationships",
    "related_commands",
    "related_docs",
    "diagnostics",
    "suggested_fixes",
    "impact"
  ],
  "metadata": {
    "schema_version": 2,
    "target": {
      "raw": "POST /posts",
      "kind": "route",
      "selector": "POST /posts"
    },
    "options": {
      "format": "json",
      "deep": false,
      "include_diagnostics": true,
      "include_neighbors": true,
      "include_execution_flow": true,
      "include_related_commands": true,
      "include_related_docs": true,
      "type": "route"
    },
    "graph": {
      "graph_version": 1,
      "framework_version": "dev-main",
      "source_hash": "deterministic-source-hash"
    },
    "command_prefix": "php bin/foundry",
    "impact": {
      "node_id": "route:POST:/posts",
      "node_type": "route",
      "risk": "high"
    }
  }
}
```

Representative `--markdown` output:

```markdown
## POST /posts

**Type:** route

### Summary
POST /posts handles requests through the compiled application graph. It dispatches the publish_post feature action. It emits post.created. It triggers notify_followers.

### Responsibilities
- Handle POST /posts requests
- Dispatch the publish_post feature action

### Execution Flow
- request
- auth guard
- permission guard (posts.create)
- rate_limit guard
- request_validation guard
- transaction guard
- publish_post feature action
- post.created
- notify_followers

### Dependencies
- feature:publish_post
- permission:posts.create
- schema:app/features/publish_post/input.schema.json
- schema:app/features/publish_post/output.schema.json

### Emits
- event:post.created

### Triggers
- job:notify_followers

### Permissions
- posts.create
- defined in: feature:publish_post
- enforced by: guard:permission:publish_post:posts_create

### Schema Interaction
- reads: schema:app/features/publish_post/input.schema.json
- writes: schema:app/features/publish_post/output.schema.json

### Graph Relationships
- inbound: feature:publish_post

### Related Commands
- `php bin/foundry inspect execution-plan POST /posts --json`
- `php bin/foundry inspect graph --command=POST /posts --json`
- `php bin/foundry inspect node route:POST:/posts --json`
- `php bin/foundry inspect pipeline --json`
- `php bin/foundry inspect route POST /posts --json`
- `foundry verify feature publish_post --json`
- `foundry verify graph --json`
- `foundry verify pipeline --json`
- `php vendor/bin/phpunit`

### Related Docs
- docs/architecture-tools.md
- docs/execution-pipeline.md
- docs/how-it-works.md
- docs/reference.md

### Diagnostics
- INFO: Event has no subscribers: post.created

### Suggested Fixes
- Add a subscriber or workflow for event: post.created

### Impact
- risk: high
- affected_features: publish_post
- affected_routes: POST /posts
- affected_events:
- affected_jobs:
- affected_projections: execution_plan_index.php, feature_index.php, routes_index.php
```

Ambiguity and error handling are deterministic and part of the public UX contract:

```text
Ambiguous target: "publish"

Did you mean:

  publish_post (feature)
  app/features/publish_post/input.schema.json (schema)
  app/features/publish_post/output.schema.json (schema)

Use a more specific target, or prefix with type:

  foundry explain feature:publish_post
  foundry explain schema:app/features/publish_post/input.schema.json
  foundry explain schema:app/features/publish_post/output.schema.json
```

```json
{
  "error": {
    "code": "EXPLAIN_TARGET_KIND_UNSUPPORTED",
    "category": "validation",
    "message": "Unsupported explain target kind: unknown.",
    "details": {
      "kind": "unknown",
      "supported_kinds": [
        "feature",
        "route",
        "event",
        "workflow",
        "command",
        "job",
        "schema",
        "extension",
        "pipeline_stage"
      ]
    }
  }
}
```

```json
{
  "error": {
    "code": "EXPLAIN_TARGET_NOT_FOUND",
    "category": "not_found",
    "message": "Explain target not found.",
    "details": {
      "target": "missing_subject"
    }
  }
}
```

```json
{
  "error": {
    "code": "PRO_LICENSE_REQUIRED",
    "category": "authorization",
    "message": "Foundry Pro is not enabled. Run `foundry pro enable <license-key>` to enable Pro features.",
    "details": {
      "command": "explain",
      "required_features": ["architecture_explanation"]
    }
  }
}
```

Extension and app integrations can enrich explanations by contributing deterministic `ExplainContributorInterface` sections through `ExplainContributorRegistry` before rendering. Contributor payloads stay structured and renderer-neutral via `ExplainContribution`, are normalized through `ExplainSection`, and render after the canonical sections according to `sectionOrder`.

## Extension Integration

The architecture tools layer introduces extension-visible graph analyzers via explicit extension API. Core registers default analyzers through `CoreCompilerExtension::graphAnalyzers()`. Additional analyzers can be introduced by custom extensions through the same interface.

## Development Loop

Recommended loop for graph-native changes:

1. Edit source-of-truth manifests/schemas/tests under `app/features/*`.
2. `foundry compile graph --json`
3. `foundry doctor --json`
4. `foundry inspect graph --event=post.created --format=mermaid --json`
5. `foundry prompt "<instruction>" --json`
6. Run verify and PHPUnit commands from suggested actions.

## Related Examples

- `examples/hello-world`
- `examples/workflow-events`
- `examples/architecture-tools`
- `docs/example-applications.md`
