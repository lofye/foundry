# AI Pipeline Example

`examples/ai-pipeline` is a supplemental app slice that groups AI-oriented features into a readable multi-step layout. It is useful for studying prompt files, schema files, and action boundaries across several related features.

What it teaches:

- organizing AI-oriented feature folders
- keeping prompts next to schemas and actions
- inspecting a multi-step slice through the graph

How to use it:

1. Copy `examples/ai-pipeline/app/features/*` into a Foundry app's `app/features/` tree.
2. Optionally copy `examples/ai-pipeline/public/index.php`.
3. From that generated app, run:

```bash
foundry compile graph --json
foundry inspect graph --feature=submit_document --json
foundry inspect graph --feature=fetch_ai_result --json
foundry doctor --feature=classify_document --json
foundry verify graph --json
```
