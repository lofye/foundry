# Foundry App Scaffolding

The app scaffolding layer adds graph-native generators for the most common application slices:
- whole-project starters (`minimal`, `standard`, `api-first`)
- starter kits (`server-rendered`, `api`)
- CRUD resources from resource definitions
- server-rendered form partial generation
- admin resource generation
- uploads/media profile generation
- listing/search/filter/sort/pagination definitions

All generated artifacts remain source-of-truth files first (`app/features/*`, `app/definitions/*`).
The semantic compiler then compiles those files into the canonical graph and emits projections.

Project scaffolds also emit first-run inspectability output under:
- `docs/generated/*`
- `docs/inspect-ui/*`

## New Commands

Generate:
- `foundry new --starter=standard --json`
- `foundry new . --starter=standard --json`
- `foundry new my-app --starter=standard --json`
- `foundry init app my-app --starter=minimal --json`
- `foundry generate starter server-rendered --json`
- `foundry generate starter api --json`
- `foundry generate resource <name> --definition=<file> --json`
- `foundry generate admin-resource <name> --json`
- `foundry generate uploads avatar --json`
- `foundry generate uploads attachments --json`

Inspect/verify:
- `foundry inspect resource <name> --json`
- `foundry verify resource <name> --json`

Codemod:
- `foundry codemod run foundation-definition-v1-normalize --dry-run --json`

## Graph Nodes

App scaffolding definitions compile to dedicated graph nodes:
- `starter_kit`
- `resource`
- `admin_resource`
- `upload_profile`
- `listing_config`
- `form_definition`

These are linked to feature nodes, execution plans, and diagnostics through compiler passes.

## Projections

App scaffolding emits additional graph-derived projections:
- `starter_index.php`
- `resource_index.php`
- `admin_resource_index.php`
- `upload_profile_index.php`
- `listing_index.php`
- `form_index.php`

## Development Loop

1. Edit or generate source definitions/features.
2. Compile graph: `foundry compile graph --json`
3. Inspect resource/pipeline/impact surfaces.
4. Verify graph/contracts/resource.
5. Run tests.

## Notes

- No parallel runtime middleware stack is introduced.
- Guards/interceptors remain pipeline-native via feature manifests.
- Definitions are versioned (`version: 1`) and codemod-ready.

## Related Examples

- `examples/hello-world`
- `examples/blog-api`
- `examples/reference-blog`
- `examples/app-scaffolding`
- `docs/example-applications.md`
