# Contributor PR Checklist

Use this checklist before merging any framework change.

## Contract Safety
- [ ] Does this change affect a documented user-facing contract?
- [ ] If yes, were docs updated first or in the same change?
- [ ] If yes, were examples re-aligned to actual behavior?
- [ ] If yes, was determinism preserved?
- [ ] If yes, does this require a versioning decision?

## Explain System Safety
- [ ] If `foundry explain` was touched, does the CLI remain thin?
- [ ] Do collectors still collect only data?
- [ ] Do analyzers still interpret only structured context?
- [ ] Does `ExplanationPlanAssembler` still own section ordering and merging?
- [ ] Do renderers still consume only assembled plan data?
- [ ] Does JSON output preserve stable top-level shape?
- [ ] Does deep mode preserve the same structure and only add detail?

## Determinism
- [ ] Same input still produces identical output
- [ ] No timestamps leaked into stable output
- [ ] No randomness leaked into stable output
- [ ] No ordering instability leaked into stable output
- [ ] No environment-specific values leaked into stable output

## Testing
- [ ] Was a failing test added first for the bug or regression?
- [ ] Were relevant unit tests updated?
- [ ] Were relevant integration tests updated?
- [ ] Does coverage remain above 90% for affected areas?
- [ ] Were focused tests run first, then broader verification?

## Documentation
- [ ] README/docs/help text match actual behavior
- [ ] No aspirational examples remain
- [ ] No example was changed unless implementation required it
- [ ] CLI/help output remains aligned with docs

## Scaffold / Generator Safety
- [ ] If scaffold behavior changed, were scaffold docs/tests updated too?
- [ ] If generated output changed, was the generator fixed rather than the output patched?

## Architecture Safety
- [ ] No layer boundaries were collapsed for convenience
- [ ] No renderer accesses graph/compiler/runtime state directly
- [ ] No CLI command contains hidden analysis logic
- [ ] No new shortcut was introduced that future phases will have to undo

## Release / Versioning Check
- [ ] Patch release: bug fix only, no contract change
- [ ] Minor release: additive and backward compatible
- [ ] Major release required if CLI/JSON/section structure/explain semantics changed

## Final Merge Check
- [ ] This change would not surprise a user or break tooling unexpectedly
- [ ] Spec, implementation, docs, and tests all agree
- [ ] This PR is safe to build on