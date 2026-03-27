# Foundry Example Applications

Foundry should prove its architecture through examples, not just through feature lists. This page links the official example set and explains what each example is meant to teach.

When working inside this framework repository, use:

```bash
php bin/foundry compile graph --json
php bin/foundry inspect graph --json
php bin/foundry doctor --json
php bin/foundry verify graph --json
php bin/foundry verify pipeline --json
```

When you import an example into a generated Foundry app, switch to:

```bash
foundry compile graph --json
foundry inspect graph --json
foundry doctor --json
foundry verify graph --json
foundry verify pipeline --json
```

If your shell does not resolve current-directory executables, use `./foundry ...`.

## Official Set

- [Hello World](../examples/hello-world/README.md): the smallest readable example for feature structure, manifests, schemas, doctor usage, and graph inspection.
- [API-First](../examples/blog-api/README.md): a CRUD-style HTTP API slice that shows the recommended route-per-feature layout.
- [Extension Example](../examples/extensions-migrations/README.md): the canonical extension, migration, and codemod reference.
- [Workflow And Events](../examples/workflow-events/README.md): a compact editorial flow that demonstrates workflow definitions plus event emit and subscribe edges.
- [Reference Blog](../examples/reference-blog/README.md): a richer build kit with commands, a paste-ready LLM prompt, and starter content for a blog with admin login and RSS.

## Supplemental Examples

- [Dashboard](../examples/dashboard/README.md): authenticated profile and upload patterns.
- [AI Pipeline](../examples/ai-pipeline/README.md): AI-oriented feature grouping and prompt/schema layout.
- [Compiler Core](../examples/compiler-core/README.md): compile, impact, and migration behavior.
- [Architecture Tools](../examples/architecture-tools/README.md): doctor, prompt, and graph-visualization surfaces.
- [Execution Pipeline](../examples/execution-pipeline/README.md): pipeline topology and execution-plan output.
- [App Scaffolding](../examples/app-scaffolding/README.md): starter, resource, admin, and upload generation inputs.
- [Integration Tooling](../examples/integration-tooling/README.md): API resources, notifications, generated docs, and testing hooks.

## Canonical Patterns

Every official example should make these patterns visible:

- feature folders remain the source of truth
- manifests and schemas stay beside the action and tests they describe
- graph inspection and doctor are part of the normal edit loop
- pipeline behavior should be inspectable through CLI output
- extension usage stays explicit and versioned

## Suggested Order

1. Start with [Hello World](../examples/hello-world/README.md).
2. Move to [API-First](../examples/blog-api/README.md) or [Workflow And Events](../examples/workflow-events/README.md), depending on the app shape you need.
3. Read [Extension Example](../examples/extensions-migrations/README.md) when you need custom compiler or pack behavior.
4. Use [Reference Blog](../examples/reference-blog/README.md) when you want a fuller end-to-end build brief.

## Thresholds Alignment

Thresholds should be treated as the richer real-app reference. The smaller examples above exist to teach isolated ideas quickly; Thresholds is where those ideas should be compared against a production-shaped application.
