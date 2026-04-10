### Decision: establish canonical-identifiers as a standalone feature
Timestamp: 2026-04-10T16:00:00-04:00

**Context**
- Foundry needs a framework-wide policy for handling normalized user input without weakening canonical identifier rules.
- This concern affects multiple CLI flows and is broader than the context-persistence feature.

**Decision**
- Create a standalone feature named `canonical-identifiers`.
- Treat canonical identifier behavior as its own feature rather than placing it inside context-persistence or a generic cleanup bucket.

**Reasoning**
- Canonical identifier behavior is a cross-cutting framework policy.
- A dedicated feature keeps scope clear and avoids turning unrelated cleanup into an unstructured bucket.
- This preserves clean feature boundaries and makes future work easier to track.

**Alternatives Considered**
- Add the work to `context-persistence`.
- Create a broad `framework-cleanup` feature.

**Impact**
- Identifier normalization and canonicalization can evolve as a coherent feature with its own spec, state, and decision history.
- Future CLI normalization work now has a clear canonical home.
