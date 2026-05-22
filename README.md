# Saqr

**Grounded retrieval-augmented Q&A library for Saudi Arabia's cybersecurity and data-protection frameworks.**

Saqr (Arabic: *صقر*, "falcon") answers questions about Saudi cybersecurity and data-protection regimes — **NCA** (ECC, CCC, CSCC, DCC, TCC, OSMACC, SCyWF), **SAMA** (CSF, ITGF, BCM), **CST** (CRF), **Aramco** (SACS-002), **SDAIA** (PDPL), and **ISO 27001** — using a curated practitioner corpus, deterministic keyword retrieval, and (optionally) an LLM for fluent answer synthesis.

The corpus is plain JSON. The retriever is plain PHP. The optional generator is a thin Anthropic Messages API caller. **No vendor lock-in, no embeddings service, no database.**

---

## Use Saqr from Claude Desktop / Cursor (MCP)

Saqr ships an MCP server so any MCP-compatible AI client can query the corpus as tools.

### Option A — via npm (requires PHP 8.1+ on the host)

Add to your `claude_desktop_config.json`:

```jsonc
{
  "mcpServers": {
    "saqr": {
      "command": "npx",
      "args": ["-y", "@cybersafe-lab/saqr-mcp"],
      "env": { "ANTHROPIC_API_KEY": "sk-ant-..." }
    }
  }
}
```

### Option B — via Docker (no PHP required)

```jsonc
{
  "mcpServers": {
    "saqr": {
      "command": "docker",
      "args": [
        "run", "-i", "--rm",
        "-e", "ANTHROPIC_API_KEY",
        "ghcr.io/cybersafe-lab/saqr-mcp:latest"
      ]
    }
  }
}
```

Verify the Docker image's signature (optional):

```bash
cosign verify ghcr.io/cybersafe-lab/saqr-mcp:latest \
  --certificate-identity-regexp 'https://github.com/cybersafe-lab/saqr/.*' \
  --certificate-oidc-issuer 'https://token.actions.githubusercontent.com'
```

Tools: `saqr_search`, `saqr_compare_frameworks`, `saqr_explain_control`, `saqr_show_corpus`.
See `docs/mcp.md` for the full integration guide.

---

## Quickstart

```bash
composer require cybersafe-lab/saqr
```

```php
<?php
require 'vendor/autoload.php';

$corpus   = \Saqr\Corpus::loadFromFile(__DIR__ . '/corpus/frameworks.json');
$pipeline = new \Saqr\Pipeline($corpus);

$result = $pipeline->ask('What is NCA ECC?');
echo $result['answer'];
```

CLI demo (no setup beyond `composer install`):

```bash
php examples/cli.php "What is NCA ECC?"
php examples/cli.php "ما هو نظام حماية البيانات الشخصية؟"
```

---

## How it works

```
            ┌──────────────┐       ┌──────────────┐       ┌──────────────┐
question ─→ │ Retriever    │ ─top─→│ Generator    │ ─────→ answer (HTML)
            │ (keyword     │  k    │ (Anthropic,  │       
            │  scoring +   │       │  optional)   │       
            │  AR→EN map)  │       └──────────────┘       
            └──────┬───────┘                                
                   │                                        
            ┌──────▼───────┐                                
            │ Corpus       │                                
            │ (JSON file)  │                                
            └──────────────┘                                
```

1. **Corpus** — A JSON file of practitioner-written entries. Each entry has a category, a list of keywords (English and transliterated terms), and an `answer` field with the actual content.
2. **Retriever** — Lowercase the question, expand any Arabic phrases to their English keyword equivalents, then score each corpus entry by summing the lengths of its keywords that match the question. Return the top-k entries by score. No embeddings; deterministic and explainable.
3. **Generator** (optional) — If `SAQR_ANTHROPIC_KEY` (or `ANTHROPIC_API_KEY`) is set, the top-k entries are passed to Claude Haiku as `[SOURCE 1]…[SOURCE N]` context with a strict grounding system prompt. The model returns sanitized HTML using only `<strong>`, `<em>`, `<br>`. If no key is set, the retriever returns the top entry verbatim.
4. **Rate limiter** — A `RateLimiterInterface` is composed into `Pipeline`. The bundled `InMemoryRateLimiter` is fine for CLI and single-process use; plug in Redis or any other backend for production.

---

## Coverage

| Authority | Frameworks in corpus |
|---|---|
| **NCA** | ECC, CCC, CSCC, DCC, TCC, OSMACC, SCyWF |
| **SAMA** | CSF, ITGF, BCM |
| **CST** | CRF |
| **Saudi Aramco** | SACS-002 / CCC (contractor) |
| **SDAIA** | PDPL |
| **International** | ISO 27001 |
| **Cross-framework** | Comparisons (ECC↔ISO, ECC↔CCC, SAMA↔NCA, PDPL↔DCC, Aramco↔NCA) |
| **Practitioner advice** | Where to start, maturity, audit, third-party |

The corpus is **curated practitioner notes**, not regulator-issued text. It encodes one practitioner's read of the frameworks. **Always verify critical decisions against the official regulator publication.**

---

## Configuration

| Option | Where | Default |
|---|---|---|
| Anthropic API key | env `SAQR_ANTHROPIC_KEY` or `ANTHROPIC_API_KEY`, or `Generator` constructor arg | none (corpus-only fallback) |
| Model | `Generator` constructor `$model` | `claude-haiku-4-5` |
| Max tokens | `Generator` constructor `$maxTokens` | `500` |
| Per-client hourly cap | `Pipeline` constructor `$perClientHourlyCap` | `20` |
| Global daily cap | `Pipeline` constructor `$globalDailyCap` | `2000` |
| Rate-limiter backend | `Pipeline` constructor `$rateLimiter` | `InMemoryRateLimiter` |

---

## Requirements

- PHP **7.4** or newer (tested on 7.4, 8.0, 8.1, 8.2, 8.3)
- ext-json (always available)
- ext-mbstring (for Arabic text handling)
- ext-curl (for the optional Anthropic call)

---

## Project layout

```
saqr/
├── src/
│   ├── Corpus.php
│   ├── Retriever.php
│   ├── Generator.php
│   ├── Pipeline.php
│   ├── Exception/InvalidCorpusException.php
│   └── RateLimiter/
│       ├── RateLimiterInterface.php
│       └── InMemoryRateLimiter.php
├── corpus/
│   └── frameworks.json
├── examples/
│   └── cli.php
└── .github/workflows/ci.yml
```

---

## Why a curated corpus, not RAG-over-PDFs?

Saudi cybersecurity regulator documents are short, dense, and easily misread by general-purpose embeddings — especially the cross-framework relationships ("ECC and CCC overlap here," "PDPL and DCC do data classification differently"). Embedding-search will give you the right paragraph for the wrong question.

A curated, practitioner-written corpus is **higher precision** for the same effort:

- Every entry was written by someone who has actually advised a Saudi client on that framework.
- Keywords are picked by someone who knows what visitors ask (including the Arabic phrasings).
- Cross-framework comparisons are first-class entries, not artifacts of paragraph adjacency.
- The corpus is **inspectable** — anyone reviewing the repo can see exactly what Saqr knows.

If you need RAG-over-PDFs, build that. Saqr is a different design point.

---

## Contributing

PRs welcome, especially:

- Additional corpus entries (with citations to the regulator publication)
- Framework integrations under `examples/` (Laravel, Symfony, Slim, plain PSR-15)
- Translations / bilingual README
- Rate-limiter implementations (Redis, Memcached, APCu)

Please:

- Keep corpus entries **practitioner-voiced**, not vendor-marketing-voiced.
- Cite the regulator publication for any factual claim about control counts, dates, or versions.
- Run `composer lint` (PHP `-l` on all `src/*.php`) before submitting.

---

## Security

Report security issues to **security@malyahya.com**. Coordinated disclosure preferred; please give a reasonable window before publishing.

---

## License

Apache-2.0 — see [LICENSE](LICENSE).

Copyright 2026 Mohammed AlYahya.
