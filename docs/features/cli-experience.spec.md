# Feature Spec: cli-experience

## Purpose
- Improve the usability, discoverability, and ergonomics of the Foundry CLI.
- Keep CLI interactions fast, explicit, deterministic, and friendly for both humans and agents.

## Goals
- Provide discoverable command surfaces and help text.
- Add shell autocomplete for supported shells.
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
- Supported shells can consume generated completion scripts for command discovery.
- Dynamic completion can expose feature names and active execution-spec ids where appropriate.
- CLI help, registry metadata, and surface verification remain aligned.
- Unsupported or invalid completion requests fail clearly.

## Acceptance Criteria
- Canonical CLI commands remain stable and verifiable.
- Autocomplete support exists for supported shells.
- Dynamic completion reflects real feature/spec state deterministically.
- CLI surface verification remains green after usability changes.
- Documentation explains how to enable and use the CLI ergonomics features.

## Assumptions
- CLI usability improvements will continue to grow as a dedicated concern rather than being scattered across unrelated features.
- The CLI registry and verifier remain the canonical sources for exposed command surfaces.
