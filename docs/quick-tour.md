# Quick Tour

The shortest reliable Foundry path is: understand the source-of-truth model, discover the main inspect and verify commands, then run the core compile loop.

In this repository use `php bin/foundry ...`. In generated apps use `foundry ...`.

If you prefer a fixed onboarding sequence, start with [Guided Learning Paths](guided-learning-paths.html).

## Short path

1. Start with [Intro](intro.md).
2. Read [How It Works](how-it-works.md) and [Architecture Overview](architecture/architecture-overview.md).
3. Run `php bin/foundry help inspect`, `php bin/foundry help verify`, and `php bin/foundry help generate`.
4. Run the core compile and verification loop below.
5. Open [Example Applications](example-applications.md) and start with the `Hello World` example.
6. Use the generated [Graph Overview](graph-overview.md), [Interactive CLI Index](cli-index.html), [Architecture Explorer](architecture-explorer.html), [Command Playground](command-playground.html), and [CLI Reference](cli-reference.md) once you want reference depth.

## Core commands

```bash
php bin/foundry help inspect
php bin/foundry help verify
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
