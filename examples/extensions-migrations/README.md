# Extensions And Migrations Examples

`examples/extensions-migrations` is the official extension example in the Foundry example set.

Run these commands from the Foundry framework repository root with `php bin/foundry ...`.
If you apply the same extension flow inside a generated app, switch to `foundry ...`.

This directory demonstrates extension and migration foundations:

- **Example A**: explicit extension registration and a minimal extension pass.
- **Example B**: pack/capability metadata, lifecycle state, and load order exposed through extension descriptors.
- **Example C**: definition migration path from feature manifest v1 to v2.
- **Example D**: deterministic codemod dry-run output.

## Example A - Minimal Extension Registration

```php
<?php
declare(strict_types=1);

return [
    \Foundry\Extensions\Demo\DemoCapabilityExtension::class,
];
```

Run:

```bash
php bin/foundry inspect extensions --json
php bin/foundry inspect extension foundry.demo --json
php bin/foundry verify extensions --json
```

## Example B - Pack/Capability Inspection

The demo extension publishes the `demo.notes` pack and capability `demo.notes.annotate`.

Inspect payloads now include:

- lifecycle stages
- load order
- registration diagnostics
- extension and pack metadata schemas

Run:

```bash
php bin/foundry inspect packs --json
php bin/foundry inspect pack demo.notes --json
php bin/foundry inspect compatibility --json
php bin/foundry doctor --json
```

## Example C - Migration Example

Input: `migration/feature.v1.yaml`

```yaml
version: 1
feature: publish_post
route:
  method: post
  path: /posts
auth:
  strategy: bearer
llm:
  risk: medium
```

Target: `migration/feature.v2.yaml`

```yaml
version: 2
feature: publish_post
route:
  method: POST
  path: /posts
auth:
  strategies: [bearer]
llm:
  risk_level: medium
```

Dry run command:

```bash
php bin/foundry migrate definitions --path=app/features/publish_post/feature.yaml --dry-run --json
```

## Example D - Codemod Example

Codemod dry run:

```bash
php bin/foundry codemod run feature-manifest-v1-to-v2 --dry-run --json
```

See `codemod/dry-run.json` for a representative JSON payload.
