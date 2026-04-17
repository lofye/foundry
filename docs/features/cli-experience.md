# Feature: cli-experience

## Purpose
- Improve the usability, discoverability, and ergonomics of the Foundry CLI.

## Current State
- The Foundry CLI already exposes a verified command surface through command registration and CLI surface verification.
- Command contracts are treated as deterministic and automation-safe.
- No dedicated shell autocomplete support is assumed to exist yet.
- Execution-spec invocation ergonomics have already improved through shorthand forms such as `<feature> <id>`.

## Open Questions
- Should completion support remain shell-script based only, or should a more general completion abstraction exist later?
- When should additional shells beyond bash and zsh be supported?
- Which command families benefit most from dynamic completion beyond `implement spec`?

## Next Steps
- Add bash and zsh autocomplete support.
- Expose dynamic completion for features and active execution-spec ids.
- Keep CLI help, registry metadata, and surface verification aligned with the new completion command.
