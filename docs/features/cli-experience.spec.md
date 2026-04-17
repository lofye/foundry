# Feature Spec: cli-experience

## Purpose
- Improve the usability, discoverability, and ergonomics of the Foundry CLI.
- Keep CLI interactions fast, explicit, deterministic, and friendly for both humans and agents.

## Goals
- Provide discoverable command surfaces and help text.
- Add shell autocomplete for bash and zsh.
- Keep command invocation deterministic and unambiguous.
- Expose CLI capabilities through stable verification and registry surfaces.
- Make common workflows easier without weakening Foundry’s explicit contracts.

## Non-Goals
- Do not redesign the full Foundry command model.
- Do not introduce fuzzy or ambiguous command resolution.
- Do not prioritize clever shell integration over deterministic behavior.
- Do not couple CLI usability features to unrelated runtime behavior.

## Constraints
- CLI behavior must remain deterministic.
- CLI help and autocomplete must reflect the actual registered command surface.
- New usability features must not slow down ordinary command execution materially.
- Automation-facing surfaces must remain stable and trustworthy.
- CLI ergonomics must not weaken active/draft or canonical-identity rules.

## Expected Behavior
- Foundry provides a reliable command surface for human and agent workflows.
- Bash and zsh can consume generated completion scripts for command discovery.
- Static completion covers top-level commands and known subcommands from the registered CLI surface.
- Dynamic completion can expose feature names and active execution-spec ids where appropriate, excluding drafts by default.
- CLI help, registry metadata, and surface verification remain aligned.
- Unsupported or invalid completion requests fail clearly.

## Acceptance Criteria
- Canonical CLI commands remain stable and verifiable.
- Autocomplete support exists for bash and zsh.
- Dynamic completion reflects real feature/spec state deterministically, including active-only execution-spec id completion by default.
- CLI surface verification remains green after usability changes.
- Documentation explains how to generate and use bash and zsh completion and that active execution specs are completed by default.

## Assumptions
- CLI usability improvements will continue to grow as a dedicated concern rather than being scattered across unrelated features.
- The CLI registry and verifier remain the canonical sources for exposed command surfaces.
