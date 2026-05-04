# Feature: cli-experience

## Purpose
- Improve the usability, discoverability, and ergonomics of the Foundry CLI.

## Current State
- Foundry provides a reliable command surface for human and agent workflows.
- Canonical CLI commands remain stable and verifiable through the current command registry and CLI surface verification.
- The stable `completion` command emits deterministic bash and zsh completion scripts.
- Static completion derives from the registered CLI surface and covers top-level commands and known subcommands.
- Dynamic `implement spec` completion lists feature names from `docs/features/` and active execution-spec ids from `docs/features/<feature>/specs/`, excluding drafts by default.
- CLI help, registry metadata, and surface verification remain aligned for the current registered command surface, including `completion`.
- Unsupported or invalid completion requests fail clearly.
- CLI surface verification is currently green.
- The developer-facing docs explain how to generate and use bash and zsh completion and that active execution specs are completed by default.

## Open Questions
- Should completion support remain shell-script based only, or should a more general completion abstraction exist later?
- When should additional shells beyond bash and zsh be supported?
- Which command families benefit most from dynamic completion beyond `implement spec`?

## Next Steps
- Evaluate whether additional command families need dynamic completion beyond `implement spec`.
- Decide when additional shells beyond bash and zsh should be supported.
- Decide whether shell-script completion remains sufficient or a broader completion abstraction is warranted later.
