# Upgrade Safety

Foundry ships `upgrade-check` so applications can assess upgrade readiness before changing framework constraints.

Run:

```bash
foundry upgrade-check --json
foundry upgrade-check --target=1.0.0 --json
```

Reports are structured around four questions:

- what is affected
- why it matters
- what version introduced the upgrade issue
- how to migrate

## Config Compatibility Aliases

Legacy config aliases are normalized during validation today, but they are not the canonical 1.0 shape.

Typical examples:

- `config.storage`: `$.local_root` -> `$.root`
- `config.ai`: `$.default_provider` -> `$.default`
- `config.database`: top-level connection keys -> `$.connections.<name>`

Upgrade-check surfaces these as upgrade warnings with the original source path and the exact suggested fix from schema validation.

## Legacy CLI Aliases

`init app` remains a compatibility alias for scaffolding, but upgrade-check treats it as legacy CLI usage for 1.0 readiness.

Migrate scripts and docs from:

```bash
foundry init app <target>
```

to:

```bash
foundry new [target]
```

## Feature Manifest V1

Feature manifest v2 is the canonical source-of-truth format. If an app still contains `version: 1` manifests, upgrade-check reports the affected manifest, the migration rules that apply, and the exact migrator command to run.

Use:

```bash
foundry inspect migrations --json
foundry migrate definitions --path=app/features/<feature>/feature.yaml --write
```

## Extension Compatibility

Upgrade-check reuses extension and pack compatibility metadata to evaluate the selected target framework version.

Typical blockers include:

- extension framework version constraints that exclude the target release
- pack version constraints that exclude the target release
- missing extension dependencies or pack capabilities
- conflicting node or projection providers

## Legacy Projection Fallback

If runtime compatibility projections exist under `app/generated/*` without matching build projections under `app/.foundry/build/projections/*`, upgrade-check reports that fallback as a compiler/runtime upgrade risk.

Rebuild projections with:

```bash
foundry compile graph --json
```
