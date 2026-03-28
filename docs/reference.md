# Reference

Foundry’s reference section mixes generated and curated material.

Generated pages:

- [Graph Overview](graph-overview.md)
- [Feature Catalog](features.md)
- [Route Catalog](routes.md)
- [Schema Catalog](schemas.md)
- [CLI Reference](cli-reference.md)
- [API Surface Policy](api-surface.md)
- [Upgrade Reference](upgrade-reference.md)

Curated companion pages:

- [Public API Policy](public-api-policy.md)
- [Extension Author Guide](extension-author-guide.md)
- [Extensions And Migrations](extensions-and-migrations.md)
- [Upgrade Safety](upgrade-safety.md)

Refresh generated reference source files with:

```bash
php bin/foundry generate docs --format=markdown --json
```

The website repo renders and publishes the public docs site. Legacy local preview only:

```bash
php scripts/build-docs.php
```
