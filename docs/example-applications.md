# Foundry Example Applications

Foundry should prove its architecture through examples, not just through feature lists. This page links the current example set and explains what each group is meant to teach.

If you are brand new to Foundry, scaffold an app, run `help inspect` and `help verify`, then start with [Hello World](../examples/hello-world/README.md).

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

The first-run walkthrough currently maps to this example taxonomy in a deterministic way:

- `blog-api` loads the canonical Blog API example directly.
- `extensions-migrations` loads a composed reference setup that combines the canonical Hello World app with the Extensions And Migrations assets.

## canonical

- [Hello World](../examples/hello-world/README.md): the smallest readable example for feature structure, manifests, schemas, and the inspect/doctor/verify loop.
- [Blog API](../examples/blog-api/README.md): the canonical HTTP slice with one collection read, one item read, and one protected write.
- [Workflow And Events](../examples/workflow-events/README.md): a compact editorial flow that demonstrates workflow definitions, event edges, route params, and job dispatch.

## reference

- [Extensions And Migrations](../examples/extensions-migrations/README.md): the canonical extension, migration, and codemod reference.
- [Reference Blog](../examples/reference-blog/README.md): a richer build kit with commands, a paste-ready LLM prompt, and starter content for a blog with admin login and RSS.

## framework

- [Compiler Core](../examples/compiler-core/README.md): compile, impact, and migration behavior.
- [Architecture Tools](../examples/architecture-tools/README.md): doctor, prompt, graph visualization, and architecture inspection surfaces.
- [Execution Pipeline](../examples/execution-pipeline/README.md): pipeline topology and execution-plan output.
- [App Scaffolding](../examples/app-scaffolding/README.md): starter, resource, admin, and upload generation inputs.
- [Integration Tooling](../examples/integration-tooling/README.md): API resources, notifications, generated docs, and testing hooks.

## Canonical Patterns

Every `canonical` example should make these patterns visible:

- feature folders remain the source of truth
- manifests and schemas stay beside the action and tests they describe
- graph inspection and doctor are part of the normal edit loop
- pipeline behavior should be inspectable through CLI output
- generated output is compiled, not hand-edited or committed as authored source

## Suggested Order

1. Start with [Hello World](../examples/hello-world/README.md).
2. Move to [Blog API](../examples/blog-api/README.md) or [Workflow And Events](../examples/workflow-events/README.md), depending on the app shape you need.
3. Read [Extensions And Migrations](../examples/extensions-migrations/README.md) when you need custom compiler or pack behavior.
4. Use [Reference Blog](../examples/reference-blog/README.md) when you want a fuller end-to-end build brief.
