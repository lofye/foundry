# Generate

`foundry generate` is the explain-driven architecture modification surface.

Core flows:

```bash
foundry generate "add feature" --mode=new
foundry generate "refine feature" --mode=modify --target=<feature>
foundry generate "repair feature" --mode=repair --target=<feature>
foundry generate "refine feature" --mode=modify --target=<feature> --interactive
foundry generate "add feature" --mode=new --explain --json
foundry generate "add feature" --mode=new --git-commit --json
```

Notes:

- generate plans against the current explain-derived system state
- `--json` includes plan confidence and outcome confidence with deterministic bands, factors, and warnings
- successful runs persist pre/post architectural snapshots in `.foundry/snapshots`
- successful runs persist the latest architectural diff in `.foundry/diffs/last.json`
- successful dry-runs and applied runs persist generate records in `app/.foundry/build/history`
- `--explain` renders the updated explain output after a successful generate run
- default human output now includes concise plan/outcome confidence and the next explain/generate iteration commands
- when Git is available, generate checks repository state before applying changes and returns Git metadata in `--json`
- `--allow-dirty` lets generate proceed in a dirty repository, but explicit warnings stay attached to the result
- `--git-commit` stages only safe generate-owned files and creates a commit after successful verification; it never commits by default
- `--allow-pack-install` lets generate install a missing pack before planning when a pack generator is required
- `--interactive` or `-i` adds an approval layer that renders summary, detail, and unified file diffs before execution
- interactive review supports approve, reject, `exclude action <n>`, `exclude file <path|n>`, `toggle risky`, `inspect graph`, and `inspect explain`
- interactive JSON output records the original plan, modified plan when applicable, user decisions, risk classification, executed actions, and verification results
- generate JSON output includes `safety_routing`, a deterministic recommendation for the `generate-with-safety-routing` skill contract, plus the signals and reasons behind the route
- default human output includes the recommended safety-routing mode so developers and agents see the same routing hint
- high-risk interactive plans require explicit confirmation before execution

Iteration loop:

```bash
foundry generate "add feature" --mode=new
foundry generate "refine feature" --mode=modify --target=<feature> --interactive
foundry explain --diff
foundry generate "refine feature" --mode=modify --target=<feature>
```
