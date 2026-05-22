# Contributing

## Architecture note: polyglot repo (PHP + TypeScript)

Saqr is canonically a PHP library. The MCP server in `mcp/` is a thin
TypeScript wrapper that spawns the PHP CLI as a subprocess — it does
NOT reimplement retrieval logic. There is exactly one retrieval
implementation in `src/Retriever.php`, and exactly one canonical
corpus in `corpus/frameworks.json`.

### Touching the retriever

`src/Retriever.php` line 35 uses `strlen()` on UTF-8 keywords. This
returns BYTE count, not codepoint count. The Arabic byte-scoring is
intentional and load-bearing — see
`tests/Characterization/RetrieverCharacterizationTest.php`. If you
need to change scoring, write an ADR explaining the migration path.

### Adding a corpus entry

Corpus PRs go through manual review (SEC-007). The `corpus-lint`
CI job rejects entries containing instruction-shaped strings
(`ignore previous instructions`, `system:`, `[SOURCE`, etc.).
