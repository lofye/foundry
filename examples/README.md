# Framework Examples

Foundry examples are split into three groups:

- `canonical`: primary copyable example applications that show how to build with Foundry today
- `reference`: richer in-repo kits and larger build references
- `framework`: examples that explain framework, compiler, and tooling surfaces

The `canonical` examples are intentionally small and keep only authored source files. They are not standalone Composer projects, and they do not commit `app/generated/*`.

The first-run walkthrough currently exposes two onboarding choices:

- `blog-api`: a direct copy of the canonical Blog API example
- `extensions-migrations`: a composed reference setup that combines the canonical Hello World app with the Extensions And Migrations assets

## canonical

- [Hello World](hello-world/README.md): the smallest readable Foundry app, showing one feature folder, schemas, context manifests, and the inspect/doctor/verify loop.
- [Blog API](blog-api/README.md): the canonical HTTP slice with one collection read, one item read, and one protected write.
- [Workflow And Events](workflow-events/README.md): a compact editorial flow showing workflows, event edges, route params, and job dispatch.

## reference

- [Extensions And Migrations](extensions-migrations/README.md): extension registration, pack metadata, migrations, and codemod examples.
- [Reference Blog](reference-blog/README.md): a richer build kit with exact commands, an LLM prompt, and starter content for a blog with admin login and RSS.

## framework

- [Compiler Core](compiler-core/README.md)
- [Architecture Tools](architecture-tools/README.md)
- [Execution Pipeline](execution-pipeline/README.md)
- [App Scaffolding](app-scaffolding/README.md)
- [Integration Tooling](integration-tooling/README.md)
