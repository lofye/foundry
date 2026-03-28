# Version Snapshot Legacy Inputs

`docs/versions/` is deprecated as a framework-side publishing source.

Canonical authored docs live in `docs/`.
The website repo owns public rendering/publishing and the authoritative published version snapshots.
If this directory is retained temporarily, treat it only as legacy input for the deprecated framework-local preview helper in `scripts/build-docs.php`.

Recommended snapshot page names:

- `index.md`
- `quick-tour.md`
- `how-it-works.md`
- `reference.md`

Generated snapshot material can also be stored under nested paths such as `generated/features.md` or `generated/cli-reference.md`.
Do not treat anything here as the published source of record.
