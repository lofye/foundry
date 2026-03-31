# Generate

`foundry generate` is the explain-driven architecture modification surface.

Core flows:

```bash
foundry generate "add feature" --mode=new
foundry generate "refine feature" --mode=modify --target=<feature>
foundry generate "repair feature" --mode=repair --target=<feature>
foundry generate "add feature" --mode=new --explain --json
```

Notes:

- generate plans against the current explain-derived system state
- `--json` includes plan confidence and outcome confidence with deterministic bands, factors, and warnings
- successful runs persist pre/post architectural snapshots in `.foundry/snapshots`
- successful runs persist the latest architectural diff in `.foundry/diffs/last.json`
- `--explain` renders the updated explain output after a successful generate run
- default human output now includes concise plan/outcome confidence and the next explain/generate iteration commands
- `--allow-pack-install` lets generate install a missing pack before planning when a pack generator is required

Iteration loop:

```bash
foundry generate "add feature" --mode=new
foundry explain --diff
foundry generate "refine feature" --mode=modify --target=<feature>
```
