# Phase 0C Examples

This directory demonstrates Phase 0C graph-native developer capabilities.

- **Example A**: `doctor` architecture diagnostics from the compiled graph.
- **Example B**: graph visualization output derived from graph nodes/edges.
- **Example C**: structured `prompt` context bundle for AI-assisted edits.

## Example A - Doctor

Command:

```bash
php vendor/bin/foundry doctor --json
php vendor/bin/foundry doctor --feature=publish_post --strict --json
```

See `doctor/doctor.sample.json` for representative output shape.

## Example B - Graph Visualize

Command:

```bash
php vendor/bin/foundry graph visualize --events --format=mermaid --json
php vendor/bin/foundry graph visualize --routes --format=dot --json
```

See:

- `visualize/events.mermaid`
- `visualize/routes.dot`

## Example C - Prompt

Command:

```bash
php vendor/bin/foundry prompt "add bookmark endpoint for posts" --feature-context --dry-run --json
```

See `prompt/prompt.sample.json` for representative payload shape.

