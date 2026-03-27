# Foundry Docs

Foundry’s documentation is split between curated architecture writing and generated reference pages built from the framework’s own graph, schema, and CLI metadata.

Use these entry points when you are orienting yourself:

- [Quick Tour](quick-tour.md) for the shortest path through compile, inspect, verify, and docs generation.
- [How It Works](how-it-works.md) for the graph, pipeline, and architecture model.
- [Reference](reference.md) for generated feature, schema, graph, and CLI pages.

The docs site is built statically with:

```bash
php scripts/build-docs.php
```

That build merges the hand-written pages in `docs/` with generated reference content and publishes the current site plus versioned snapshots under `public/docs/`.
