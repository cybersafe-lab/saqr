# Saqr LinkedIn Showcase Sprint — Design

**Date:** 2026-07-29
**Goal:** Make Saqr worthy of a permanent LinkedIn **Featured** card. Audience: mixed, weighted toward employers/recruiters — engineering depth is the spine, business framing the lede.
**Viewer path:** Featured card → case study (live post 3154 on the website) → GitHub repo + live chatbot.
**Scope rule:** enhance only what a viewer sees on that path. Audit Phases 1–2 (WP adapter plugin, deploy automation) are explicitly out of scope.

## Context (verified 2026-07-29)

- Library (`this repo`): `Pipeline::ask()` already returns `top` with frozen entry IDs (`src/Pipeline.php:63-93`, `corpus/ids.lock`). The Generator never sees IDs (`src/Generator.php:63`) and `sanitizeHtml` strips non-`strong/em/br` markup — so per-claim inline citation is out of scope by design.
- Live chatbot: independent reimplementation in the WP child theme at `/Volumes/SSD/public_html/wp-content/themes/mefolio-child/inc/grc-assistant.php` (REST route `lab/v1/grc`). Its KB is a hardcoded 32-entry PHP array with **no IDs** (`:906-1072`). Response shape today: `{"answer", "source"}`; JS reads only `answer`. Rate-limit branches return `{"answer"}` only.
- Corpus: 30 entries, 4 keys each (`id, category, keywords, answer`) — no `framework`, `title`, or refs/URLs. `framework` is null through the whole pipeline; MCP `search` description promises scores that are always null (`Retriever.php:58` drops them).
- Evals: 72 retrieval-only cases (64 en / 8 ar), ID-overlap metrics (hit@1/hit@3/MRR), CI regression gate vs `eval/baseline.json`. Arabic hit@1 = 0.625.
- README: stale ASCII diagram, stale SACS-002 claim (live KB moved to SACS-210), no images anywhere in repo. Not on Packagist (VCS install).

## 0. Factual correction found during planning (2026-07-30)

The corpus swaps two NCA acronyms: entry `nca-ccc` describes Critical Systems controls and `nca-cscc` describes Cloud controls, but officially CSCC = Critical Systems (2019) and CCC = Cloud (2020); `ecc-vs-ccc` inherits the error and `src/Retriever.php`'s Arabic alias map is consistent with the swap. Fix first (content swap — IDs stay frozen), including affected eval cases; add a corrected `ecc-vs-cscc` entry (corpus becomes 31 entries).

## 1. Source display (centerpiece)

**Corpus schema:** add optional `refs` array per entry:

```json
"refs": [{ "title": "NCA Essential Cybersecurity Controls (ECC-2:2024)", "url": "https://nca.gov.sa/..." }]
```

- `Corpus.php` normalization gains `refs` (default `[]`). `bin/corpus-lint` validates shape (title + https URL). IDs stay frozen; `ids.lock` untouched.
- All 30 entries get curated refs to official regulator documents (NCA, SAMA, CST, SDAIA, Aramco, ISO). Comparison/meta entries reference both underlying docs or none if nothing official applies — no fabricated links; every URL must be verified reachable.
- Populate the `framework` field explicitly per entry (deriving from `category` is too coarse — `CST / ARAMCO / PDPL` is one bucket); MCP `search` output then carries real `framework` values.
- MCP: `search`/`compare`/`explain_control` responses include `refs`; fix `tools.ts` description to drop the score promise (or expose the score — decision: **drop the promise**, Retriever public API stays stable, its characterization tests untouched).
- Fix stale `Retriever` docblock (`@return`) while in the file.

**WP chatbot:**
- KB entries (32) gain `id` aligned to `ids.lock` where content matches; WP-only entries (e.g. OTCC, SACS-210) get new ids in the same naming style; they live only in the WP KB and are **not** added to `ids.lock`.
- KB entries gain `refs` (same shape as corpus).
- REST response becomes `{"answer", "source", "sources": [{"id","title","url"}]}` — additive; rate-limit branches unchanged; JS must tolerate missing `sources`.
- Chat UI renders a compact "Sources" row under each answer: linked chips (title → official URL, target=_blank, rel=noopener). RTL-safe for Arabic answers.
- Sources are rendered from the structured field only — never parsed from model output.
- Query log (`ma_grc_log_query`) records matched entry ids instead of a 60-char answer prefix.

**Truthful framing:** UI copy says the answer is *grounded in* these retrieved sources (retrieval-level attribution), not that the model cited them per-claim.

**Deploy:** same SSH path as prior fixes (Hostinger, backups + LiteSpeed purge + live verification), following the precedent in STATE.md.

## 2. Arabic eval expansion

- Grow `eval/questions.jsonl` from 8 → ~40 Arabic cases: coverage across all 30 entry IDs, phrasing variety (formal/dialectal, with/without diacritics, transliterated acronyms like "نكا"/"NCA").
- Re-run `php bin/saqr-eval`; update `eval/baseline.json` and `docs/eval.md` with per-language numbers.
- If Arabic metrics drop below the current 0.625 hit@1, cheap retriever normalization fixes only (e.g. alef/ta-marbuta folding already in `normalizeArabic` — extend if a clear gap shows). Anything structural gets logged as a documented finding, not fixed in this sprint.
- CI eval gate keeps passing; baseline update is an intentional, explained commit.

## 3. Repo showcase

- README overhaul: mermaid architecture diagram rendered to committed SVG (shows Corpus → Retriever → Pipeline → Generator, the `top`/sources path, RateLimiter, and the CLI/MCP boundary), demo GIF (chatbot answering with sources) + MCP-in-Claude-Desktop screenshot, badges (CI, license, PHP ≥8.2, Node ≥20), an "Engineering highlights" section (97 tests/298 assertions, characterization + snapshot + eval suites, frozen-ID corpus lint, CSP-clean deployment), fix stale SACS-002 claim and entry-shape description.
- Packagist publish (**user action**, ~10 min with their account); then README restores plain `composer require cybersafe-lab/saqr`.
- New docs/media live under `docs/` (e.g. `docs/img/`).

## 4. Case study + Featured card

- Rewrite post 3154 (DB edit with JSON backup, same procedure as the 2026-07-21 correction): lede for business readers (what it does, why it matters for Saudi compliance), then engineering narrative — problem → constraints (Arabic-first, deterministic keyword retrieval, no embeddings/DB, thin LLM layer) → design decisions → measured results (eval numbers, test counts, CI) → screenshots incl. sources row → links to repo and live chatbot.
- All claims must match repo reality post-sprint (the 2026-07-21 corrections set the honesty precedent: "same retrieval design", not "same library").
- LinkedIn Featured card: link to the case study URL; verify OG title/description/image render well in LinkedIn's preview; produce a 1200×627 thumbnail if the current OG image is weak. Draft card headline + 2-line description for the user to paste.

## Error handling

- WP JS: absent/empty `sources` → no row rendered (rate-limit and `source:"none"` branches).
- Corpus refs: lint rejects malformed refs; missing refs are allowed (empty array), so partial curation never breaks the build.
- Eval baseline: never auto-lowered — a regression fails CI unless the baseline change is deliberate.

## Testing

- Corpus/lint: new lint rules covered by running `bin/corpus-lint` in CI (existing step).
- Library: unit tests for `Corpus` refs normalization; MCP vitest suite updated for new response fields.
- WP: manual contract check of `lab/v1/grc` response shape + live browser verification (sources row, RTL, no console errors) — consistent with existing practice; no WP test harness exists and building one is out of scope (Phase 1).
- Evals: regression gate as above.

## Out of scope

WP adapter plugin (Phase 1), deploy automation (Phase 2), per-claim citation, exposing retrieval scores, corpus/WP-KB full reconciliation (only ID alignment + refs), answer-quality (generator) evals.
