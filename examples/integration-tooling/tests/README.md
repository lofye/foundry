# Deep Tests Example

Generate deep tests for a feature or resource:

```bash
php vendor/bin/foundry generate tests api_create_post --mode=deep --json
php vendor/bin/foundry generate tests posts --mode=resource --json
php vendor/bin/foundry generate tests --all-missing --mode=deep --json
```

Deep mode uses graph context (auth, schemas, events, jobs, route shape) to select scenario templates.
