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
- Provide deterministic automatic implementation-log appends for successful active execution-spec completion.
- Block only real execution-spec contradictions against canonical non-goals and negative constraints.
- Prevent framework-repository execution specs from routing into the generic `app/features/*` scaffold pipeline.
- Keep agent-facing framework, app, and skill instructions aligned to canonical feature-doc paths, including feature context stems, execution-spec paths, plan paths, and implementation-log path.

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
- Automatic implementation logging must not log draft specs, must prevent duplicate entries, and must surface log-write failures clearly and deterministically.
- Canonical conflict detection must require stronger evidence than topic-word overlap alone before blocking `implement spec`.
- Framework-repository execution specs must not create or modify `app/features/*` scaffolds for framework-internal work.

## Expected Behavior
- Active execution specs live at `docs/features/<feature>/specs/<id>-<slug>.md`; drafts live under `docs/features/<feature>/specs/drafts/<id>-<slug>.md`.
- `<id>` uses one or more dot-separated 3-digit numeric segments such as `001` or `015.002.001`.
- `implement spec` resolves active execution specs deterministically from canonical `<feature>/<id>-<slug>` refs, from exact `<feature> <id>` shorthand within a feature, and may still accept a unique active filename shorthand.
- `<feature> <id>` resolution matches the canonical hierarchical id exactly, resolves active specs only, and fails clearly for malformed ids, unknown features, unknown active ids, draft-only matches, or ambiguous duplicates.
- Execution spec headings use `# Execution Spec: <id>-<slug>` and match the filename only.
- Resolver validation rejects noncanonical filenames and noncanonical headings.
- `plan feature` allocates the next root id without colliding with existing active or draft spec ids, including hierarchical descendants.
- Existing spec files in the repository use the canonical filename-only heading format.
- `spec:new <feature> "<slug>"` creates a draft execution spec under `docs/features/<feature>/specs/drafts/<id>-<slug>.md`.
- `spec:new` normalizes slug input to lowercase kebab-case, rejects empty or low-information results, and creates the required draft template without modifying existing specs.
- `spec:new` fails clearly when feature input is invalid, the target path already exists, or allocation cannot proceed deterministically.
- `spec:log-entry` resolves one active execution spec deterministically and emits the exact canonical implementation-log entry content expected by validation.
- `spec:log-entry` fails clearly for draft-only, malformed, or unknown targets and does not generate implementation-log entries for drafts.
- `spec:validate` scans active and draft execution specs, reports filename, placement, heading, duplicate-id, and forbidden-metadata violations, and exits non-zero when violations exist.
- `spec:validate` also requires exact implementation-log coverage for active execution specs, ignores drafts, and reports missing coverage deterministically.
- `spec:validate` returns both terminal output and JSON payloads that include every detected violation for repair workflows and automation.
- Successful `implement spec` runs for active execution specs append one required-format entry to `docs/features/implementation-log.md`.
- Draft execution specs are never logged as implemented, and repeated completion of the same active spec does not duplicate the log entry.
- If the implementation log cannot be updated, `implement spec` must surface that failure clearly and deterministically. It must not report a clean successful completion, and it may return a partial-success status such as `completed_with_issues` when the implementation itself succeeded but required logging could not be completed.
- Canonical conflict detection evaluates positive execution-spec instructions against forbidden clauses extracted from canonical non-goals and negative constraints, and aligned instructions that merely share topic nouns do not trigger `EXECUTION_SPEC_CONFLICTS_WITH_CANONICAL_SPEC`.
- In the framework repository, `implement spec` blocks framework-internal execution specs before the generic `app/features/*` scaffold path and fails explicitly until a dedicated framework-internal implementation path exists.
- Agent-facing instructions use `docs/features/<feature>/<feature>.*` for canonical feature context, `docs/features/<feature>/specs/*.md` and `docs/features/<feature>/specs/drafts/*.md` for execution specs, `docs/features/<feature>/plans/*.md` for plans, and `docs/features/implementation-log.md` for implementation history.
- Agent-facing instructions do not describe `docs/specs/*`, `docs/<feature>/*`, or `<id>-<slug>` feature-context stems as active canonical locations.

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
- `spec:log-entry` returns deterministic machine-readable fields for canonical spec ref, canonical spec path, exact `- spec:` line, and full entry content for an active spec.
- `spec:log-entry` rejects draft-only, malformed, and unknown targets clearly.
- `spec:validate` detects invalid filenames, misplaced specs, duplicate ids, incorrect headings, and forbidden metadata without modifying files.
- `spec:validate` fails when an active execution spec is missing an exact matching implementation-log entry and does not require log entries for drafts.
- PHPUnit coverage covers the execution-spec validation service and CLI command behavior.
- Successful active execution-spec implementation appends exactly one correctly formatted implementation-log entry automatically.
- Draft execution specs are not auto-logged.
- Implementation-log write failures surface clearly and deterministically and do not appear as a clean successful completion.
- `implement spec <feature> <id>` resolves the correct active spec deterministically and fails clearly for malformed, draft-only, ambiguous, or unknown shorthand targets.
- Execution specs that reinforce canonical behavior without instructing a forbidden action are not blocked by canonical conflict detection.
- True contradictions against canonical non-goals or negative constraints still return `EXECUTION_SPEC_CONFLICTS_WITH_CANONICAL_SPEC` deterministically.
- Framework-repository execution specs do not create `app/features/<feature>/` scaffolds for framework-internal features such as `execution-spec-system`.
- `implement spec` returns a deterministic explicit block instead of silently generating misplaced app-feature output in the framework repository.
- Agent-facing framework, app, and skill instruction surfaces reflect only the canonical feature-doc path contract and preserve historical stale-path references only in explicitly historical or migration contexts.

## Assumptions
- Feature directories continue to provide context and execution state.
- Fully qualified CLI references may continue to include the feature name even though the filename remains the canonical spec identity.
- Child-spec allocation beyond root planning can be added later without changing the canonical filename rules.
