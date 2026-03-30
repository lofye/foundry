# Foundry Docs

Foundry’s documentation is split between curated architecture writing and generated reference pages built from the framework’s own graph, schema, and CLI metadata. Canonical source docs live in this repo under `docs/`; the website repo imports, renders, and publishes the public docs experience.

In this repository use `php bin/foundry ...`. In generated Foundry apps use `foundry ...`.

Choose an entry point based on what you need:

- New to Foundry: start with [Quick Tour](quick-tour.md), then [Example Applications](example-applications.md), then the `Hello World` example called out there.
- Contributing to the framework: start with [Contributor Portal](contributor-portal.md) and [Architecture Overview](architecture/architecture-overview.md).
- Looking for current command or graph surfaces: use [Reference](reference.md), [Interactive CLI Index](cli-index.html), and [Architecture Explorer](architecture-explorer.html).

These are the main orientation pages:

- [Guided Learning Paths](guided-learning-paths.html) for curated sequences like "Learn Foundry in 30 minutes", extension onboarding, and pipeline study.
- [Quick Tour](quick-tour.md) for the shortest path through compile, inspect, verify, and docs generation.
- [How It Works](how-it-works.md) for the graph, pipeline, and architecture model.
- [Contributor Portal](contributor-portal.md) for framework architecture, extension hooks, workflow rules, and the PR checklist.
- [Reference](reference.md) for generated feature, schema, graph, and CLI pages.

Refresh generated reference source files in the framework repo with:

```bash
php bin/foundry generate docs --format=markdown --json
```

Legacy local preview only:

```bash
php scripts/build-docs.php
```

That preview helper writes temporary framework-local output to `public/docs/`, but the website repo remains the authoritative renderer/publisher and owner of published version snapshots.
