# Foundry Docs

Foundry’s documentation is split between curated architecture writing and generated reference pages built from the framework’s own graph, schema, and CLI metadata. Canonical source docs live in this repo under `docs/`; the website repo imports, renders, and publishes the public docs experience.

Use these entry points when you are orienting yourself:

- [Quick Tour](quick-tour.md) for the shortest path through compile, inspect, verify, and docs generation.
- [How It Works](how-it-works.md) for the graph, pipeline, and architecture model.
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
