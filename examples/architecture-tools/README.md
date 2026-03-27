# Architecture Tools Examples

This directory demonstrates graph-native architecture tooling capabilities.

Run these commands from the Foundry framework repository root with `php bin/foundry ...`.
If you are trying the same flow inside a generated app, switch to `foundry ...`.

- **Example A**: `doctor` architecture diagnostics from the compiled graph.
- **Example B**: graph visualization output derived from graph nodes/edges.
- **Example C**: structured `prompt` context bundle for AI-assisted edits.

## Example A - Doctor

Command:

```bash
php bin/foundry doctor --json
php bin/foundry doctor --feature=publish_post --strict --json
```

See `doctor/doctor.sample.json` for representative output shape.

## Example B - Graph Visualize

Command:

```bash
php bin/foundry inspect graph --event=post.created --format=mermaid --json
php bin/foundry export graph --view=routes --format=dot --json
```

See:

- `visualize/events.mermaid`
- `visualize/routes.dot`

## Example C - Prompt

Command:

```bash
php bin/foundry prompt "add bookmark endpoint for posts" --feature-context --dry-run --json
```

See `prompt/prompt.sample.json` for representative payload shape.
