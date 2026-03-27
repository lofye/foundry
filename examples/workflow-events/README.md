# Workflow And Events Example

`examples/workflow-events` is the official workflow/event example. It keeps the feature set small while showing real event edges and a workflow definition that can be inspected through the graph.

What it teaches:

- event emit and subscribe relationships between features
- workflow definitions under `app/definitions/workflows`
- graph inspection by feature, event, and workflow
- doctor and verify loops for an event-driven slice

How to use it:

1. Copy `examples/workflow-events/app/features/*` into a Foundry app's `app/features/` tree.
2. Copy `examples/workflow-events/app/definitions/workflows/*` into `app/definitions/workflows/`.
3. Optionally copy `examples/workflow-events/public/index.php`.
4. From that generated app, run:

```bash
foundry compile graph --json
foundry inspect graph --event=story.review_requested --json
foundry graph inspect --workflow=editorial --json
foundry inspect graph --feature=publish_story --json
foundry doctor --feature=publish_story --json
foundry verify graph --json
foundry verify workflows --json
foundry verify pipeline --json
```
