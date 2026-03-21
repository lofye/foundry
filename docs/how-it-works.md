# How It Works

Foundry’s docs system is intentionally graph-native. The same compiler output that powers runtime projections and verification also feeds generated documentation.

Read these pages in order when you want the architecture model:

- [Semantic Compiler](semantic-compiler.md)
- [Execution Pipeline](execution-pipeline.md)
- [Architecture Tools](architecture-tools.md)
- [Contributor Vocabulary](contributor-vocabulary.md)

The generated side of the docs uses the compiled graph to publish:

- feature and route catalogs
- schema indexes
- event, job, and cache registries
- CLI surface reference
- graph metadata snapshots and sample CLI payloads

That lets the static site stay aligned with actual framework structure instead of drifting into hand-maintained reference text.
