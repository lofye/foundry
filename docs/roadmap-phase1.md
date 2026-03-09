# Foundry Roadmap Phase 1

Phase 1 adds graph-native generators for the most common application slices:
- starter kits (`server-rendered`, `api`)
- CRUD resources from resource specs
- server-rendered form partial generation
- admin resource generation
- uploads/media profile generation
- listing/search/filter/sort/pagination specs

All generated artifacts remain source-of-truth files first (`app/features/*`, `app/specs/*`).
The semantic compiler then compiles those files into the canonical graph and emits projections.

## New Commands

Generate:
- `php vendor/bin/foundry generate starter server-rendered --json`
- `php vendor/bin/foundry generate starter api --json`
- `php vendor/bin/foundry generate resource <name> --spec=<file> --json`
- `php vendor/bin/foundry generate admin-resource <name> --json`
- `php vendor/bin/foundry generate uploads avatar --json`
- `php vendor/bin/foundry generate uploads attachments --json`

Inspect/verify:
- `php vendor/bin/foundry inspect resource <name> --json`
- `php vendor/bin/foundry verify resource <name> --json`

Codemod:
- `php vendor/bin/foundry codemod run phase1-spec-v1-normalize --dry-run --json`

## Phase 1 Graph Nodes

Phase 1 specs compile to dedicated graph nodes:
- `starter_kit`
- `resource`
- `admin_resource`
- `upload_profile`
- `listing_config`
- `form_definition`

These are linked to feature nodes, execution plans, and diagnostics through compiler passes.

## Projections

Phase 1 emits additional graph-derived projections:
- `starter_index.php`
- `resource_index.php`
- `admin_resource_index.php`
- `upload_profile_index.php`
- `listing_index.php`
- `form_index.php`

## Development Loop

1. Edit or generate source specs/features.
2. Compile graph: `php vendor/bin/foundry compile graph --json`
3. Inspect resource/pipeline/impact surfaces.
4. Verify graph/contracts/resource.
5. Run tests.

## Notes

- No parallel runtime middleware stack is introduced.
- Guards/interceptors remain pipeline-native via feature manifests.
- Specs are versioned (`version: 1`) and codemod-ready.
