# Features Workspace

`Features/` is the canonical feature workspace.

Each feature directory owns:

- feature context files (`<slug>.spec.md`, `<slug>.md`, `<slug>.decisions.md`)
- execution specs (`specs/` and `specs/drafts/`)
- plans (`plans/`)
- feature-local docs (`docs/`)
- feature-owned source (`src/`)
- feature-owned tests (`tests/`)

Legacy `docs/features/` paths remain readable during migration, but canonical paths are preferred.
