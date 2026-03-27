# Official Example Applications

Foundry examples are split into two groups:

- focused app examples that show one application shape clearly
- framework/tooling examples that document compiler, extension, and CLI surfaces

The focused app examples are intentionally small and are best treated as copyable `app/*` trees plus companion docs. They are not standalone Composer projects.

## Official Set

- [Hello World](hello-world/README.md): the smallest readable Foundry app, showing one feature folder, schemas, context manifests, doctor usage, and graph inspection.
- [API-First](blog-api/README.md): a route-first CRUD slice showing how to organize an HTTP API around feature folders and contract files.
- [Extension Example](extensions-migrations/README.md): the canonical extension, pack, migration, and codemod example.
- [Workflow And Events](workflow-events/README.md): a compact event-driven editorial flow with workflow definitions and graph inspection commands.
- [Reference Blog](reference-blog/README.md): the full reference-app kit with exact commands, an LLM prompt, and starter content for a blog with admin login and RSS.

## Supplemental App Examples

- [Dashboard](dashboard/README.md): authenticated dashboard-style routes and profile/media patterns.
- [AI Pipeline](ai-pipeline/README.md): AI-oriented feature grouping and prompt/schema organization.

## Framework And Tooling Examples

- [Compiler Core](compiler-core/README.md)
- [Architecture Tools](architecture-tools/README.md)
- [Execution Pipeline](execution-pipeline/README.md)
- [App Scaffolding](app-scaffolding/README.md)
- [Integration Tooling](integration-tooling/README.md)

## Thresholds

Thresholds should be treated as the richer real-app reference. The examples in this directory stay intentionally smaller so they can teach one architecture idea at a time without hiding the underlying Foundry patterns.
