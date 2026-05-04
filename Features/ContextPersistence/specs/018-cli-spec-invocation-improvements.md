# Execution Spec: 018-cli-spec-invocation-improvements

## Feature
- context-persistence

## Purpose
- Improve the `implement spec` CLI so developers and agents can invoke execution specs with shorter, more ergonomic arguments.
- Reduce friction when referring to specs by feature and numeric id.
- Preserve deterministic, unambiguous execution-spec resolution while adding more convenient invocation forms.

## Scope
- Extend `foundry implement spec` argument parsing.
- Support invocation by feature plus spec id, without requiring the full `<feature>/<id>-<slug>` ref.
- Keep the existing fully qualified invocation forms working.
- Keep this focused on CLI invocation and resolution improvements only.

## Constraints
- Keep execution-spec resolution deterministic.
- Do not break existing `implement spec <feature>/<id>-<slug>` usage.
- Do not introduce ambiguous shorthand resolution.
- Do not guess when multiple specs could match.
- Reuse existing execution-spec resolver logic where practical.
- Prefer the smallest clear CLI improvement over a broad command redesign.

## Inputs

Expect inputs such as:
- `foundry implement spec context-persistence/018-cli-spec-invocation-improvements`
- `foundry implement spec context-persistence 018`
- `foundry implement spec context-persistence 015.001`
- existing canonical execution-spec files under `docs/features/<feature>/specs/`

If any critical input is missing:
- fail clearly and deterministically
- do not guess the feature
- do not guess the spec when multiple matches exist

## Requested Changes

### 1. Add Feature + ID Invocation Form

Add support for this invocation shape:

```bash
foundry implement spec <feature> <id>
```

Examples:

```bash
foundry implement spec context-persistence 018
foundry implement spec context-persistence 015.001
foundry implement spec execution-spec-system 004
```

This must resolve to the active execution spec in:

```text
docs/features/<feature>/specs/<id>-<slug>.md
```

### 2. Preserve Existing Invocation Forms

The existing invocation form must continue to work:

```bash
foundry implement spec <feature>/<id>-<slug>
```

If other current accepted forms exist, preserve them unless they are clearly invalid or deprecated elsewhere.

### 3. Resolution Rules

For `foundry implement spec <feature> <id>`:

- resolve only within the provided feature
- match the canonical execution-spec id exactly
- require that the matching spec be active, not in `drafts/`
- fail if no active spec with that id exists
- fail if more than one active spec would somehow match

Do not infer slug text when resolution is ambiguous.

### 4. Active-Only Behavior

The shorthand `<feature> <id>` form must resolve active specs only.

Do not resolve:
- `docs/features/<feature>/specs/drafts/<id>-<slug>.md`

If the matching id exists only in drafts, fail clearly and explain that the spec must be promoted before it can be implemented.

### 5. Error Behavior

If resolution fails, the CLI must fail clearly and deterministically.

At minimum, support clear failures for:
- missing feature argument
- missing id argument
- unknown feature
- unknown active spec id within a valid feature
- draft-only match
- ambiguous match
- malformed id

Prefer stable plain-text and JSON output suitable for automation.

### 6. JSON and Text Contract

The command must preserve the existing result contract for successful or blocked implementation runs.

This spec changes only invocation convenience and resolution behavior, not the implementation result schema itself.

For resolution failures before execution begins:
- return a stable CLI error
- keep output deterministic
- include enough detail for the user or agent to repair the invocation

### 7. Help and CLI Surface

Update:
- CLI help text
- any command registry or surface metadata
- any generated/reference docs that describe `implement spec`

The help output must make the new shorthand discoverable.

### 8. Tests

Add focused coverage proving:

- `foundry implement spec <feature>/<id>-<slug>` still works
- `foundry implement spec <feature> <id>` resolves the correct active spec
- hierarchical ids such as `015.001` resolve correctly
- draft-only matches fail clearly
- unknown ids fail clearly
- malformed ids fail clearly
- ambiguous conditions fail clearly if they can occur
- JSON/text behavior remains deterministic
- all relevant existing CLI and resolver tests still pass

## Non-Goals
- Do not add fuzzy matching by slug text alone.
- Do not auto-promote drafts.
- Do not redesign the implement-spec execution pipeline.
- Do not change framework-repo blocking behavior for framework-internal specs.
- Do not add interactive prompts in this spec.

## Canonical Context
- Canonical feature spec: `docs/context-persistence/context-persistence.spec.md`
- Canonical feature state: `docs/context-persistence/context-persistence.md`
- Canonical decision ledger: `docs/context-persistence/context-persistence.decisions.md`

## Authority Rule
- Execution-spec invocation must remain deterministic and unambiguous.
- CLI convenience must not weaken canonical spec identity or active/draft rules.
- The active execution spec identified by `<feature> <id>` must be the same one that would be identified by the canonical full ref.

## Completion Signals
- `foundry implement spec <feature> <id>` works for active specs.
- Existing full-ref invocation still works.
- Draft-only and unknown-id cases fail clearly.
- Hierarchical ids resolve correctly.
- Output remains stable and deterministic.
- Help text reflects the new invocation form.
- All tests pass.

## Post-Execution Expectations
- Developers and agents can invoke execution specs more quickly with less typing.
- CLI usage becomes more ergonomic without sacrificing determinism.
- Foundry still treats canonical filenames and active/draft placement rules as authoritative.
