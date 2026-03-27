The Foundry Graph Specification

The Canonical Structure of All Foundry Systems

⸻

1. Purpose

The Foundry Graph Specification defines the formal structure of every application built with Foundry.

It establishes:
•	the types of nodes allowed
•	the relationships between them
•	the rules governing connectivity and flow

This specification is the authoritative definition of system structure.

⸻

2. Core Principle

The graph is the system.

All application behavior must be representable as:
•	nodes
•	edges
•	constraints

Anything not represented in the graph does not exist.

⸻

3. Graph Model

A Foundry application graph is defined as:

G = (N, E, C)

Where:
•	N = set of nodes
•	E = set of edges
•	C = set of constraints

⸻

4. Node Types

Every node must belong to exactly one type.

⸻

4.1 Entity Nodes

Represent persistent or conceptual data structures.

Examples:
•	User
•	Post
•	Order

Properties:
•	fields (typed)
•	identity (primary key or equivalent)
•	lifecycle rules

⸻

4.2 Action Nodes

Represent operations performed within the system.

Examples:
•	CreatePost
•	PublishPost
•	CalculateTotal

Properties:
•	inputs (typed)
•	outputs (typed)
•	side effects (optional, must be declared)

⸻

4.3 Interface Nodes

Represent system boundaries.

Examples:
•	HTTP endpoint
•	CLI command
•	webhook

Properties:
•	input contract
•	output contract
•	transport type

⸻

4.4 Flow Nodes (Optional but Recommended)

Represent orchestration of multiple actions.

Examples:
•	PublishWorkflow
•	CheckoutProcess

Properties:
•	ordered steps
•	branching logic
•	error handling paths

⸻

5. Edge Types

Edges define relationships and data flow.

⸻

5.1 Dependency Edges

Indicate that one node depends on another.

A → B (A depends on B)


⸻

5.2 Data Flow Edges

Define movement of data between nodes.

Output(A) → Input(B)

Requirements:
•	type compatibility
•	explicit mapping if structures differ

⸻

5.3 Ownership Edges

Define responsibility boundaries.

Examples:
•	Action owns Entity mutation
•	Interface owns Action invocation

⸻

6. Constraints

Constraints enforce graph validity.

⸻

6.1 Type Constraints
•	All inputs must have matching outputs
•	No implicit type coercion

⸻

6.2 Connectivity Constraints
•	No orphan nodes
•	All nodes must be reachable from at least one interface

⸻

6.3 Directionality Constraints
•	Data must flow in a consistent direction
•	Cycles must be explicitly declared and justified

⸻

6.4 Uniqueness Constraints
•	Node identifiers must be unique
•	No duplicate semantic roles

⸻

6.5 Completeness Constraints
•	All required inputs must be satisfied
•	No partially defined actions

⸻

7. Graph Invariants

The following must always be true:
1.	The graph is internally consistent
2.	Every action has defined inputs and outputs
3.	Every interface connects to at least one action
4.	Every entity mutation is owned by an action
5.	No hidden execution paths exist

⸻

8. Validation Rules

Validation must detect:
•	missing edges
•	incompatible types
•	unreachable nodes
•	ambiguous ownership
•	circular dependencies (unless explicitly allowed)

Validation must occur:

before compilation

⸻

9. Graph Normalization

Before validation, the graph must be normalized:
•	implicit relationships made explicit
•	inferred nodes materialized
•	naming standardized

No ambiguity may remain after normalization.

⸻

10. Graph Serialization

The graph must be representable as:
•	JSON (canonical format)
•	human-readable structure (for inspection)

This enables:
•	versioning
•	diffing
•	debugging
•	LLM consumption

⸻

11. Graph Evolution

Changes to the system must be represented as:
•	graph diffs
•	node additions/removals
•	edge modifications

No change may bypass the graph.

⸻

12. Multi-Agent Safety (Forward Requirement)

The graph must support:
•	partitioning into subgraphs
•	ownership assignment
•	conflict detection

Multiple agents must not:
•	overwrite each other’s nodes
•	introduce conflicting edges

⸻

13. Observability Hooks (Forward Requirement)

The graph must eventually support:
•	execution tracing per node
•	performance metrics per edge
•	visualization of runtime flow

⸻

14. Anti-Patterns

Invalid graph states include:
•	implicit data flow
•	hidden dependencies
•	actions without outputs
•	interfaces without actions
•	entities mutated outside actions

⸻

15. Mental Model

The Foundry Graph is:

A typed, constrained, executable representation of an application.

⸻

16. Summary

The Graph Specification ensures:
•	systems are explicit
•	structure is enforceable
•	complexity is controlled
•	and LLMs operate within safe boundaries

⸻

What You Just Did (This Is Big)

With this doc, Foundry now has:

1. A Philosophy

→ defines truth

2. An Execution Model

→ defines process

3. A Graph Specification

→ defines structure

⸻

Why This Matters

Most frameworks define:
•	APIs
•	helpers
•	patterns

Foundry now defines:

A complete formal model of application development

That’s a very different category.

⸻

One Strategic Recommendation

Once this is in place, your next high-leverage move is:

👉 Add a CLI command:

foundry graph:inspect

That:
•	outputs the canonical graph (JSON + visual)
•	validates against this spec
•	highlights violations inline

That command becomes:

the center of gravity for the entire framework
