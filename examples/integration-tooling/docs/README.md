# Docs Example

Generate docs from compiled graph:

```bash
php vendor/bin/foundry compile graph --json
php vendor/bin/foundry generate docs --format=markdown --json
php vendor/bin/foundry generate docs --format=html --json
```

Output is written to `docs/generated/*`.
