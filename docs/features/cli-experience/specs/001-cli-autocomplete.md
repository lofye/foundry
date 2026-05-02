# Execution Spec: 001-cli-autocomplete

## Feature
- cli-experience

## Purpose
- Add shell autocomplete support for the Foundry CLI.
- Improve developer ergonomics and command discoverability without changing command semantics.
- Support dynamic completion for feature names and active execution spec ids.

## Scope
- Add a CLI command that emits shell completion scripts.
- Support bash and zsh.
- Support static completion for top-level commands and subcommands.
- Support dynamic completion for:
  - feature names
  - active execution spec ids within a feature
- Integrate with the existing CLI registry and verifier.

## Constraints
- Keep command behavior deterministic.
- Do not slow down normal CLI execution paths.
- Do not require external dependencies beyond standard shell capabilities.
- Do not introduce fuzzy or ambiguous completion.
- Do not redesign the CLI command model in this spec.
- Prefer a small, explicit implementation over a broad shell-integration framework.

## Inputs

Expect inputs such as:
- `foundry completion bash`
- `foundry completion zsh`
- `foundry implement spec <feature> <id>`
- execution specs under `docs/features/<feature>/specs/`

If any critical input is missing:
- fail clearly and deterministically for the completion command
- fall back to static completion only where dynamic discovery is unavailable
- do not emit broken shell code silently

## Requested Changes

### 1. Add a Completion Command

Introduce:

```bash
foundry completion <shell>
```

Supported shells:
- `bash`
- `zsh`

Behavior:
- print the completion script to stdout
- exit non-zero for unsupported shell values
- keep output deterministic

### 2. Add Static Command Completion

Completion scripts must support:
- top-level commands
- known subcommands
- stable command names registered in the CLI surface

At minimum, completion must cover the currently supported command tree well enough to make normal Foundry CLI discovery materially better.

### 3. Add Dynamic Feature Completion

When completing:

```bash
foundry implement spec <feature>
```

the completion logic must dynamically list feature names based on available execution-spec directories under:

```text
docs/features/
```

The listing must be deterministic and sorted stably.

### 4. Add Dynamic Active Spec-ID Completion

When completing:

```bash
foundry implement spec <feature> <id>
```

the completion logic must dynamically list active execution spec ids for that feature from:

```text
docs/features/<feature>/specs/
```

Requirements:
- list active specs only by default
- exclude `drafts/`
- support hierarchical ids such as `015.001`
- keep ordering deterministic

### 5. Preserve Active/Draft Rules

Autocomplete must not blur active and draft execution-spec lifecycle rules.

By default:
- complete active specs only

If you expose draft completion later, it must be explicit and must not be the default in this spec.

### 6. Integration Points

Integrate completion support through the CLI-owned surfaces.

At minimum, update the relevant runtime locations such as:
- command registration
- CLI help / usage text where appropriate
- API/CLI surface registry metadata
- CLI surface verification, if needed

Choose the smallest implementation path that keeps the feature first-class and testable.

### 7. Performance Safeguards

Completion logic must remain lightweight.

Requirements:
- avoid repeated unnecessary filesystem scans
- keep dynamic discovery simple and bounded
- keep normal non-completion CLI execution unaffected

### 8. Error Handling

The completion command must fail clearly for:
- missing shell argument
- unsupported shell argument
- internal script generation failure

Prefer stable plain-text and JSON-safe behavior if the command participates in any machine-readable surface.

### 9. Documentation

Update the relevant developer-facing docs to cover:
- how to generate a bash completion script
- how to generate a zsh completion script
- how dynamic feature/spec-id completion behaves
- that active execution specs are completed by default

### 10. Tests

Add focused coverage proving:

- `foundry completion bash` emits valid deterministic output
- `foundry completion zsh` emits valid deterministic output
- unsupported shell values fail clearly
- static command completion includes expected command names
- dynamic feature completion lists features correctly
- dynamic spec-id completion lists active ids correctly
- drafts are excluded by default
- hierarchical ids are completed correctly
- CLI surface verification still passes
- all relevant CLI tests still pass

## Non-Goals
- Do not support fish or other shells in this spec.
- Do not redesign command parsing.
- Do not introduce interactive prompts.
- Do not change execution-spec naming, allocation, or validation rules.
- Do not add fuzzy slug-based completion.

## Canonical Context
- Canonical feature spec: `docs/cli-experience/cli-experience.spec.md`
- Canonical feature state: `docs/cli-experience/cli-experience.md`
- Canonical decision ledger: `docs/cli-experience/cli-experience.decisions.md`

## Authority Rule
- CLI autocomplete must improve ergonomics without weakening determinism.
- Dynamic completion must respect canonical feature/spec identity and active/draft rules.
- Completion output must remain stable and trustworthy.

## Completion Signals
- `foundry completion bash` works.
- `foundry completion zsh` works.
- command completion is useful and deterministic.
- feature completion works.
- active spec-id completion works.
- drafts are excluded by default.
- all tests pass.

## Post-Execution Expectations
- Developers can tab-complete Foundry commands and spec invocations.
- CLI usage becomes faster and less error-prone.
- Foundry’s CLI becomes more discoverable without sacrificing contract stability.
