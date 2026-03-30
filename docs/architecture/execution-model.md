# Foundry Execution Model

Foundry turns authored source files into deterministic compiled state, then executes requests and tooling workflows against that compiled state.

## Current Pipeline

The current model is:

Author -> Compile -> Inspect/Doctor -> Verify -> Execute

Inside compile, the framework runs explicit compiler stages:

1. discovery
2. normalize
3. link
4. validate
5. enrich
6. analyze
7. emit

Those stages produce the canonical graph, projections, manifests, diagnostics, and related build metadata under `app/.foundry/build/*`.

## Source Inputs

The execution model starts from explicit authored inputs:

- feature files under `app/features/*`
- capability-specific definitions under `app/definitions/*` when used
- framework and app configuration under `config/*`

Generated artifacts are never the authored source of truth.

## Compiled State

Foundry compiles authored inputs into:

- the canonical application graph
- runtime projections and indexes
- diagnostics and integrity metadata
- exports and generated docs inputs when requested

`app/generated/*` remains a compatibility mirror of emitted runtime projections. It is generated output, not authored source.

## Runtime Execution

Runtime execution is projection-driven rather than discovery-driven.

For a typical HTTP feature the runtime flow is:

1. match a route from generated indexes
2. resolve the compiled feature and execution plan
3. run guards, validation, and pipeline behavior
4. execute the feature action
5. validate output and emit the response

When a compatibility fallback exists, it stays deterministic and compile-shaped rather than introducing hidden runtime behavior.

## Inspection And Verification

The same compiled state powers:

- `inspect` surfaces
- `doctor`
- graph and pipeline verification
- contract and compatibility verification
- generated docs and interactive docs surfaces

That shared compiled state is what keeps docs, CLI, and verification aligned with actual framework behavior.

## Determinism Rules

- Same source inputs must produce the same graph and generated artifacts.
- Ordering must remain stable and machine-readable.
- No timestamps, randomness, or hidden environment state may leak into stable outputs.
- Generated artifacts must be regenerated, not hand-edited.

## Safe Iteration Model

The intended edit loop is:

1. inspect current compiled reality
2. change the smallest authored source files
3. recompile
4. inspect or doctor the affected surfaces
5. verify graph, pipeline, and contracts
6. run tests

## Anti-Patterns

The following violate the execution model:

- editing generated output instead of authored source
- bypassing compile/verify loops after changing source-of-truth files
- adding hidden runtime discovery or middleware behavior outside the explicit pipeline model
- treating docs or examples as authoritative when they disagree with code and tests
