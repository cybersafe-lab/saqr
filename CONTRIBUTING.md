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

Each entry in `corpus/frameworks.json` has this shape:

```json
{
  "id": "stable-kebab-slug",
  "category": "ONE OF THE ALLOWED CATEGORIES",
  "keywords": ["lowercase phrase", "..."],
  "answer": "<strong>HTML</strong> practitioner answer.",
  "sources": ["NCA ECC-1:2018, control 2-3-1", "https://nca.gov.sa/..."]
}
```

- `id` is required, unique, and immutable. It is the join key the eval
  suite points at, frozen in `corpus/ids.lock`. Add new ids to the lock;
  never rename an existing one.
- `category` must be one of the allowed categories enforced by
  `corpus-lint` (AUTHORITIES, NCA FRAMEWORKS, SAMA FRAMEWORKS,
  CST / ARAMCO / PDPL, COMPARISONS, META).
- `sources` is optional for the legacy entries but REQUIRED for every new
  entry. It is provenance for review and is not served to clients.
- **Never invent** control numbers, dates, CVSS scores, or scope. If a
  fact is not in a cited source, omit it.
- `answer` must pass the brand-voice style lint: no em-dashes, no banned
  puff words (`corpus/style-bans.txt`). Run `php bin/corpus-lint` before
  opening a PR.
