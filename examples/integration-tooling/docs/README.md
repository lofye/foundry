# Docs Example

After copying the example definitions into a Foundry app, generate docs from the compiled graph:

```bash
foundry compile graph --json
foundry generate docs --format=markdown --json
foundry generate docs --format=html --json
```

Output is written to `docs/generated/*`.
