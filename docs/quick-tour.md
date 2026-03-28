# Quick Tour

The framework docs are organized around the same lifecycle the framework uses internally: author source-of-truth files, compile the graph, inspect reality, verify contracts, and refresh generated reference docs.

## Short path

1. Start with [Intro](intro.md).
2. Read [How It Works](how-it-works.md).
3. Inspect the generated [Graph Overview](graph-overview.md) and [CLI Reference](cli-reference.md).
4. Use [App Scaffolding](app-scaffolding.md) and [Example Applications](example-applications.md) when you want concrete app shapes.

## Core commands

```bash
php bin/foundry compile graph --json
php bin/foundry inspect graph --json
php bin/foundry verify graph --json
php bin/foundry verify pipeline --json
php bin/foundry verify contracts --json
php bin/foundry generate docs --format=markdown --json
```

Canonical docs are authored here, but the website repo renders and publishes the public docs site and version snapshots. `php scripts/build-docs.php` remains deprecated and exists only for framework-local preview output.

## What the generated docs cover

- curated landing pages for orientation
- generated graph snapshots
- generated schema and feature catalogs
- generated CLI and API surface reference
