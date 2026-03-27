# Foundry Public API Policy

Foundry 1.0 requires an explicit answer to one question:

What is safe to depend on?

This document defines the supported surface and the rules attached to it.

## Classification Model

Foundry classifies framework surface into four categories:

- `public_api`: safe for application code, automation, and generated-app tooling to depend on
- `extension_api`: safe for extensions and packs to implement against
- `experimental_api`: intentionally available, but still allowed to change in minor releases while clearly marked
- `internal_api`: implementation detail, not a supported dependency

Anything not explicitly listed as public, extension, or experimental defaults to `internal_api`.

## Namespace Strategy

Foundry uses an explicit metadata registry plus namespace-prefix rules.

Public runtime namespaces include:

- `Foundry\Feature\*`
- `Foundry\Events\*`
- `Foundry\DB\*`
- `Foundry\Queue\*`
- `Foundry\Storage\*`
- `Foundry\Workflow\*`
- `Foundry\Notifications\*`
- `Foundry\Localization\*`
- `Foundry\Billing\*`

Internal namespace families include:

- `Foundry\Compiler\*` unless a symbol is explicitly listed as an extension hook
- `Foundry\CLI\*`
- `Foundry\Generation\*`
- `Foundry\Documentation\*`
- `Foundry\Verification\*`
- `Foundry\Support\*`

Exact symbol overrides are used for extension contracts that live under otherwise-internal namespaces, including:

- `Foundry\Compiler\Extensions\CompilerExtension`
- `Foundry\Compiler\CompilerPass`
- `Foundry\Compiler\CompilationState`
- `Foundry\Compiler\ApplicationGraph`
- `Foundry\Compiler\Projection\ProjectionEmitter`
- `Foundry\Compiler\Migration\MigrationRule`
- `Foundry\Compiler\Codemod\Codemod`
- `Foundry\Compiler\Analysis\GraphAnalyzer`
- `Foundry\Pipeline\PipelineStageDefinition`
- `Foundry\Pipeline\StageInterceptor`

## CLI Stability

CLI commands are classified independently as:

- `stable`: semver-governed name, options, and JSON output
- `experimental`: available, but may change in minor releases while marked experimental
- `internal`: developer/framework workflow only, with no compatibility guarantee

Use the CLI itself to inspect the current classification:

```bash
foundry help --json
foundry help graph inspect --json
foundry help export graph --json
foundry inspect api-surface --json
```

## Frozen Contracts

Stable documented behavior becomes a frozen contract once it has been implemented, reviewed, and aligned with shipped examples.

From that point forward:

- stable contracts are not casually reworded, reshaped, or silently drifted
- any behavioral change must be proposed, documented first, implemented second, re-aligned in examples, and then verified through deterministic output and tests
- if a behavior matters to users or automation, it must exist in the documentation, implementation, and test suite

## Semver Rules

For listed stable `public_api` and `extension_api` surface:

- before 1.0, Foundry still treats listed stable surface as a compatibility promise
- patch releases are for bug fixes only and must not change stable contracts
- minor releases may add new stable surface only when the change is backward compatible and extends the documented contract without rewriting existing behavior
- breaking changes require a documented deprecation and upgrade note before 1.0, and a major release after 1.0
- breaking changes include stable CLI behavior changes, stable JSON contract changes, stable section-structure changes, and stable explain-semantics changes

For `experimental_api` surface:

- changes may land in minor releases
- the surface must stay clearly labeled in help/docs
- upgrade notes must call out user-visible changes

For `internal_api` surface:

- no semver guarantee is provided
- apps and extensions should not couple to it

## JSON Contract Versioning

Stable JSON output is strictly versioned.

- stable JSON output shapes must remain unchanged across patch and minor releases
- adding optional data in a backward-compatible way is allowed in minor releases only when existing keys, meanings, and ordering guarantees remain intact
- removing keys, renaming keys, changing value semantics, or restructuring stable JSON payloads requires a major release

## Documentation And Example Accuracy

Documentation is part of the contract surface, not marketing copy.

- examples must reflect real behavior and deterministic output
- generated or documented output must not be aspirational, fictional, timestamped, random, or environment-dependent
- when docs and behavior disagree, the discrepancy must be resolved explicitly rather than carried forward as drift

## Configuration, Manifests, and Generated Metadata

Stable application contracts include:

- `app/features/*/feature.yaml`
- `app/features/*/input.schema.json`
- `app/features/*/output.schema.json`
- `app/features/*/context.manifest.json`
- `app/features/*/permissions.yaml`
- `app/features/*/cache.yaml`
- `app/features/*/events.yaml`
- `app/features/*/jobs.yaml`

Stable extension registration contracts include:

- `foundry.extensions.php`
- `config/foundry/extensions.php`

Internal generated metadata includes:

- `app/generated/*.php`
- `app/.foundry/build/graph/*`
- `app/.foundry/build/projections/*`
- `app/.foundry/build/manifests/*`
- `app/.foundry/build/diagnostics/*`

These artifacts are inspectable, but not stable dependency surfaces.

## Extension Author Guidance

Extension authors should:

- depend only on symbols classified as `extension_api`
- avoid `Foundry\Compiler\Passes\*`, `Foundry\Compiler\IR\*`, and other internal compiler namespaces
- declare compatibility through extension descriptors and pack constraints
- use `foundry inspect api-surface --json` and `foundry verify compatibility --json` while developing extension packages

## Generated Reference Output

`foundry generate docs --format=markdown --json` now emits:

- `docs/generated/api-surface.md`
- `docs/generated/cli-reference.md`

Those files are generated from the same registry that powers `help` and `inspect api-surface`, so the classification policy stays consistent across docs and CLI output.
