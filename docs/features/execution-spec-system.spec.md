# Feature Spec: execution-spec-system

## Purpose
- Define one canonical execution-spec naming system for Foundry.
- Keep spec identity, hierarchy, and ordering deterministic across code, docs, and repository views.

## Goals
- Use `<id>-<slug>.md` as the canonical execution-spec filename shape.
- Support one or more dot-separated 3-digit ID segments.
- Derive hierarchy from the filename ID and draft or active status from the directory path.
- Enforce filename-only headings in execution specs.
- Keep resolver and planner behavior deterministic for hierarchical execution-spec ids.
- Provide a deterministic CLI command for creating new draft execution specs.
- Provide a deterministic CLI command for validating active and draft execution specs against canonical rules.

## Non-Goals
- Do not introduce filesystem-specific natural-sort dependencies.
- Do not duplicate `id`, `parent`, or `status` metadata inside execution spec contents.
- Do not add automatic child-spec generation in this first implementation pass.
- Do not rename existing execution-spec ids once assigned.
- Do not auto-promote drafts or execute them during creation.

## Constraints
- IDs must be immutable once assigned.
- Stored filenames must remain canonical and explicitly padded.
- Lexical sorting must preserve the intended logical ordering.
- Active and draft specs share the same identity space within a feature.
- Existing `implement spec` and `plan feature` workflows must remain deterministic and conservative.
- Draft creation must not overwrite existing files.
- Slug normalization must be deterministic and reject low-information placeholders.
- Validation must not modify files and must report all detected violations deterministically.

## Expected Behavior
- Active execution specs live at `docs/specs/<feature>/<id>-<slug>.md`; drafts live under `docs/specs/<feature>/drafts/<id>-<slug>.md`.
- `<id>` uses one or more dot-separated 3-digit numeric segments such as `001` or `015.002.001`.
- `implement spec` resolves active execution specs with canonical hierarchical ids and may still accept a unique filename shorthand.
- Execution spec headings use `# Execution Spec: <id>-<slug>` and match the filename only.
- Resolver validation rejects noncanonical filenames and noncanonical headings.
- `plan feature` allocates the next root id without colliding with existing active or draft spec ids, including hierarchical descendants.
- Existing spec files in the repository use the canonical filename-only heading format.
- `spec:new <feature> "<slug>"` creates a draft execution spec under `docs/specs/<feature>/drafts/<id>-<slug>.md`.
- `spec:new` normalizes slug input to lowercase kebab-case, rejects empty or low-information results, and creates the required draft template without modifying existing specs.
- `spec:new` fails clearly when feature input is invalid, the target path already exists, or allocation cannot proceed deterministically.
- `spec:validate` scans active and draft execution specs, reports filename, placement, heading, duplicate-id, and forbidden-metadata violations, and exits non-zero when violations exist.
- `spec:validate` returns both terminal output and JSON payloads that include every detected violation for repair workflows and automation.

## Acceptance Criteria
- Hierarchical padded execution-spec filenames are accepted and parsed deterministically.
- Parent ids can be derived from filename segments.
- Generated execution specs use filename-only headings.
- Noncanonical headings and filenames fail clearly.
- Planner allocation stays deterministic and avoids active or draft id collisions.
- Framework docs and tests reflect the canonical execution-spec naming system.
- PHPUnit coverage covers hierarchical resolution and planner allocation behavior.
- `spec:new` creates correctly named draft execution specs with the required template.
- `spec:new` emits stable success and failure output for terminals and automation.
- Draft creation writes one file on success and no files on failure.
- `spec:validate` detects invalid filenames, misplaced specs, duplicate ids, incorrect headings, and forbidden metadata without modifying files.
- PHPUnit coverage covers the execution-spec validation service and CLI command behavior.

## Assumptions
- Feature directories continue to provide context and execution state.
- Fully qualified CLI references may continue to include the feature name even though the filename remains the canonical spec identity.
- Child-spec allocation beyond root planning can be added later without changing the canonical filename rules.
