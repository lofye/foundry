# Explain

`foundry explain` is the canonical architecture introspection surface for Foundry.

Core flows:

```bash
foundry explain
foundry explain publish_post --json
foundry explain publish_post --git --json
foundry explain pack:foundry/blog --json
foundry explain --diff
foundry explain --diff --json
```

Notes:

- when no target is provided, Foundry explains the first feature or route deterministically
- `--json` returns the canonical explain contract used by other tooling
- explain output includes deterministic confidence data with a `score`, `band`, explicit `factors`, and `warnings`
- `--git` adds optional repository context for the explained target, including relevant-file dirty state and last commit metadata when Git is available
- `--diff` reads the latest explain-derived architectural diff from `.foundry/diffs/last.json`
- the diff compares `.foundry/snapshots/pre-generate.json` and `.foundry/snapshots/post-generate.json`
- `--diff --json` returns the architectural diff contract plus diff confidence derived from compatible explain snapshots
- the diff is architectural, not file-based

Iteration loop:

```bash
foundry generate "add feature" --mode=new
foundry explain --diff
foundry explain
```
