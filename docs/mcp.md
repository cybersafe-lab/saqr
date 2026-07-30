# Saqr MCP Integration Guide

## What this is

`@cybersafe-lab/saqr-mcp` is a Model Context Protocol server that exposes
Saqr's Saudi cybersecurity corpus as tools for any MCP-compatible client.

## Install

(Both options shown in the project README.)

## Tools

| Tool | Inputs | What it does |
|---|---|---|
| `saqr_search` | `{question: string}` | Free-text search of the corpus; returns up to 3 entries with official-source references |
| `saqr_compare_frameworks` | `{framework_a: string, framework_b: string}` | Crosswalk between two KSA frameworks, with the references behind the answer it returns |
| `saqr_explain_control` | `{control_ref: string}` | Looks up the entry covering the control's domain (e.g. `ECC-2-3-1`, `SAMA CSF 3.3.5`) and explains that domain, with references |
| `saqr_show_corpus` | `{}` | Lists the frameworks covered and the entry count |

### Result shapes

```jsonc
// saqr_search
{ "results": [ { "id": "pdpl",
                 "title": "Personal Data Protection Law (PDPL)",
                 "framework": "SDAIA",
                 "content": "<strong>PDPL</strong> ...",
                 "refs": [ { "title": "...", "url": "https://..." } ] } ],
  "query_normalized": "What is PDPL?" }

// saqr_compare_frameworks
{ "comparison": "...", "used_llm": false,
  "sources": ["iso-27001", "nca-ecc"],
  "refs": [ { "title": "...", "url": "https://..." } ] }

// saqr_explain_control
{ "control_id": "ECC-2-3-1", "framework": "NCA", "summary": "...",
  "refs": [ { "title": "...", "url": "https://..." } ],
  "sources": ["nca-ecc"] }

// saqr_show_corpus
{ "frameworks": ["NCA", "SDAIA", "SAMA", "..."], "entry_count": 31 }
```

`saqr_search` returns **up to** 3 results, fewer when fewer entries match and an
empty `results` array when none do. Results are ordered best-first, but there is
no `score` field: the retriever scores by keyword byte length internally and does
not expose the number, so do not build a confidence threshold on this API.
`query_normalized` echoes the question as received. `sources` on the other two
tools lists the ids of the entries retrieved, in retrieval order.

`saqr_explain_control` does **not** return the verbatim text of a control. Saqr's
corpus is practitioner notes, not regulator text, so the tool resolves the
reference to the entry covering that control's domain and explains the domain.
`control_id` echoes what you asked for; `refs` points at the regulator document
where the control itself is published.

### Official-source references

All three answer-bearing tools return a `refs` array of `{title, url}` objects
next to the answer, so any factual claim can be checked against the regulator's
own publication.

| Tool | What `refs` holds |
|---|---|
| `saqr_search` | the refs of each returned entry, per result |
| `saqr_explain_control` | the refs of the entry that answered the lookup |
| `saqr_compare_frameworks` | when `used_llm` is true, the union of the refs of every entry the comparison drew on, deduplicated by URL, retrieval order kept; when it is false the answer is one entry's text verbatim, so `refs` narrows to that entry's own refs |

`refs` is always present. It is empty for exactly two entries: the META
self-descriptions (`about-assistant`, `frameworks-index`), which describe the
assistant rather than a framework and so have nothing to cite. Those two are also
the entries that answer what new users open with ("help", "what frameworks do
you cover"), so `refs: []` is a normal first response, not an edge case. Read
`refs[0]` defensively.

## Environment variables

| Var | Purpose | Default |
|---|---|---|
| `ANTHROPIC_API_KEY` | If set, the LLM layer runs. If unset, all tools return curated corpus entries verbatim. | unset |
| `SAQR_CORPUS_PATH` | Override corpus location. Must be absolute, must not contain `..`, must be under `$HOME` / `/usr/share/saqr/` / `/etc/saqr/` / `/app/`. | bundled `corpus/frameworks.json` |
| `SAQR_PHP_PATH` | Override which PHP binary to invoke. Bare basename or absolute path. No shell metacharacters. | `php` (PATH resolved) |
| `SAQR_LOG_LEVEL` | `error` / `warn` / `info` / `debug` | `warn` |

## Trust model

The MCP server runs locally. It spawns one PHP child process per MCP session and only:
- Reads the bundled `corpus/frameworks.json` (or `SAQR_CORPUS_PATH` if set)
- Calls the Anthropic API IF `ANTHROPIC_API_KEY` is set
- Writes JSON-RPC responses to stdout

It does not open network sockets except for the Anthropic API call when explicitly enabled by setting the key.

The corpus is a trust boundary: changes ship via PRs reviewed by maintainers, and a CI corpus-lint job rejects entries that fail schema, frozen-ID, brand-voice, or instruction-injection checks. Retrieval quality is regression-gated on every PR against a labeled eval set (see [eval.md](eval.md)).

## Troubleshooting

| Symptom | Fix |
|---|---|
| `PHP runtime not found` | Use the Docker option instead, or `brew install php` / `apt install php-cli`. |
| `SAQR_CORPUS_PATH must be under...` | Move your custom corpus into `$HOME/...` or set the env var to the bundled default. |
| Server crashes repeatedly (`saqr backend repeatedly crashing`) | Check `~/.config/claude/logs/` for the underlying PHP error. File an issue with the redacted log. |
