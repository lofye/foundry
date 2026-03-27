# The Philosophical and Architectural Foundations of Foundry

⸻

## 0. Purpose

Foundry exists to make LLM-assisted software development:
•	deterministic
•	structurally sound
•	reproducible
•	and scalable

It does this by transforming development from code generation into system compilation.

⸻

## 1. The Core Principle

The agent is not the problem. The environment is.

LLMs fail when:
•	context is implicit
•	structure is absent
•	constraints are unenforced

Foundry fixes the environment.

⸻

## 2. The Unit of Development

Traditional systems:

Code is the source of truth.

Foundry:

The system specification is the source of truth.

Code is:
•	generated
•	replaceable
•	secondary

⸻

## 3. The Canonical Application Graph

Every Foundry application is represented as a graph of:
•	entities
•	actions
•	relationships
•	flows

This graph must be:
•	explicit
•	complete
•	internally consistent

No feature may exist outside the graph.

⸻

## 4. Compilation Over Generation

All systems must follow:

Spec → Graph → Compile → Execute

Rules:
•	No direct code mutation without spec alignment
•	Compilation must validate structure before execution
•	Invalid systems must fail early

⸻

## 5. Determinism

Given the same inputs:
•	the same spec must produce the same system
•	no hidden state may influence outcomes
•	no implicit context may exist

⸻

## 6. Explicitness

All behavior must be:
•	declared
•	inspectable
•	versionable

Hidden logic is forbidden.

⸻

## 7. Structural Integrity

The system must enforce:
•	input/output contracts
•	dependency clarity
•	graph consistency

Violations must be detected before runtime.

⸻

## 8. Iteration Model

Iteration occurs through:
•	spec modification
•	recompilation
•	validation

Not through:
•	ad hoc code edits
•	prompt-based patching

⸻

## 9. Role of LLMs

LLMs are:
•	executors of intent
•	translators of specification into implementation

LLMs are not:
•	system designers
•	sources of truth

⸻

## 10. Simplicity

Simplicity is defined as:

The absence of ambiguity in system structure.

Not:
•	fewer lines of code
•	fewer files

⸻

## 11. Observability (Forward Requirement)

All systems must evolve toward:
•	full graph visibility
•	execution traceability
•	measurable behavior

⸻

## 12. Extensibility

All features must be:
•	composable
•	modular
•	graph-integrated

⸻

## 13. Anti-Patterns

The following are explicitly disallowed:
•	hidden prompt state
•	implicit architecture
•	direct code-first feature creation
•	bypassing the graph

⸻

## 14. The Goal

Foundry aims to make software development:

predictable, inspectable, and correct by construction.
