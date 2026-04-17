# Execution Spec: 001-freeze-time-for-deterministic-graph-compilation

## Feature
- compiler-determinism

## Purpose
Ensure graph compilation is deterministic for identical inputs when time is controlled, preventing nondeterministic metadata (e.g., timestamps) from causing divergent outputs.

## Goals
- Graph compilation produces identical outputs for identical inputs when time is fixed.
- Tests can reliably compare full graph outputs without excluding metadata fields.
- Production behavior continues using real current UTC time by default.

## Non-Goals
- Do not remove timestamps from graph metadata.
- Do not change production-time semantics.
- Do not weaken equality comparisons in tests.

## Constraints
- Determinism must be achievable without altering production runtime behavior.
- Time control must be explicit and injectable.
- No hidden global state.

## Expected Behavior
- GraphCompiler must obtain time via an injectable clock abstraction.
- When the same clock (fixed time) is used, multiple compilations of identical input produce identical outputs.
- Derived identifiers (e.g., build_id) must remain consistent when inputs and time are identical.
- When using real-time clock, behavior remains unchanged.

## Acceptance Criteria
- A test can inject a fixed clock and verify identical graph outputs across multiple runs.
- No test relies on removing or ignoring timestamp fields to pass.
- Production code paths continue to use real time by default.
- No flakiness in determinism-related tests across repeated runs.

## Inputs
- GraphCompiler
- Clock abstraction
- ApplicationGraph output structure

## Requested Changes
- Ensure GraphCompiler uses an injectable Clock instead of direct time calls.
- Ensure Clock supports fixed-time mode for tests.
- Update tests to inject fixed time where deterministic comparison is required.
- Verify no other nondeterministic sources exist in graph compilation.

## Canonical Context
- src/Compiler/GraphCompiler.php
- src/Support/Clock.php
- tests/Unit/GraphCompilerTest.php

## Authority Rule
Deterministic output must depend only on:
- input graph
- configuration
- explicitly provided time

## Completion Signals
- Determinism tests pass consistently.
- Full PHPUnit suite passes repeatedly.
- No timestamp-related flakiness observed.

## Post-Execution Expectations
- Graph compilation becomes a reliable deterministic operation under controlled conditions.
- Future features can depend on stable graph identity.
