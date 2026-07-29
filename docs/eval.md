# Saqr retrieval eval

Saqr's answer quality is measured against a labeled question set, run on every
PR. The runner loads the production corpus (`corpus/frameworks.json`) and calls
the same `Retriever` that production serves, so there is no separate retrieval
implementation to drift.

## Metrics

- **hit@1** — a gold id is the top result.
- **hit@3** — a gold id is within the top 3.
- **MRR** — mean reciprocal rank of the first matching id.

## Current results (2026-07-29)

| Scope | hit@1 | hit@3 | MRR | n |
|-------|-------|-------|-----|---|
| Overall | 0.953 | 0.972 | 0.962 | 106 |
| en | 0.955 | 0.985 | 0.970 | 66 |
| ar | 0.950 | 0.950 | 0.950 | 40 |

Regenerate with `php bin/saqr-eval`.

Arabic coverage went from 8 cases over 7 corpus ids to 40 cases over all 31 ids, in
practitioner MSA: bare topics, mixed Arabic with Latin acronyms, and both hamza
spellings (أيزو / ايزو, أرامكو / ارامكو). Read the `ar` number for what it is:
corpus keywords are English, so an Arabic question only retrieves when
`Retriever::normalizeArabic` recognizes one of its phrasings and appends the
matching English keywords. `ar` hit@1 therefore measures **alias coverage of the
phrasings in this set**, not open-domain Arabic understanding. Phrasings the map
has not seen score zero and return nothing, which is why the misses below are worth
more attention than the headline figure.

## Adding a question

Append a line to `eval/questions.jsonl`:
`{"q": "...", "lang": "en|ar", "expected_ids": ["<real-corpus-id>"], "note": "..."}`
Every `expected_id` must exist in the corpus (the runner errors otherwise).

## Known limitations

Five cases in the set are known misses: two Arabic, three English that predate the
Arabic expansion. All five are properties of the scoring model, not gaps in the
alias map, so they are recorded rather than patched. Scores below are keyword byte
lengths, the retriever's unit of relevance.

- **Arabic comparison questions that name ECC in Arabic** (`ecc-vs-ccc`,
  `ecc-vs-cscc`). "ما الفرق بين الضوابط الأساسية وضوابط الحوسبة السحابية؟" resolves
  to `nca-ecc` (42) and `nca-ccc` (35); `ecc-vs-ccc` scores 0. The alias for
  الضوابط الأساسية emits three `nca-ecc` keywords, and a comparison entry's
  keywords are short acronym pairs (`ecc vs ccc` = 10, `ecc and ccc` = 11), so the
  entry answering the question cannot outweigh the two entries it compares. The
  comparison entries whose counterparts are lighter do resolve correctly in Arabic
  (`sama-vs-nca` beats 11, `aramco-vs-nca` beats 10, `pdpl-vs-dcc` beats 7,
  `iso-vs-nca` beats 14). Fixing the ECC pairs would need comparison-intent
  detection, or Arabic keywords on the comparison entries themselves.
- **`What is NCA CCC and who does it apply to?`** returns `nca-overview` first
  (`what is nca` = 11) ahead of `nca-ccc` (`nca ccc` = 7); correct entry is rank 2.
  A generic authority keyword outscores the specific framework it contains.
- **`How is SAMA CSF structured and what maturity levels does it use?`** returns
  `maturity` first (`maturity` + `maturity level` = 22) ahead of `sama-csf`
  (`sama csf` = 8); correct entry is rank 2. Two matches on a cross-cutting topic
  entry beat one match on the framework the question names.
- **The English `pdpl-vs-dcc` case** (`How does PDPL relate to NCA DCC`) returns
  `nca-dcc` (7) and `pdpl` (4); `pdpl-vs-dcc` scores 0, because its keywords are the
  literal pairs `pdpl vs dcc` / `pdpl and dcc` and this phrasing produces neither.
  Same shape as the Arabic comparison miss, reached from English.

Three Arabic-specific traps were found while triaging. They are worth knowing before
adding aliases, since substring matching has no notion of word boundaries:

- **Definite-article assimilation.** A key beginning with ال never matches the
  لل form: للحوسبة does not contain الحوسبة, and للتدقيق does not contain التدقيق.
  Both aliases were rewritten to stems (حوسبة السحابية, تدقيق). The same trap is
  live for الموردين (vs للموردين) and الهيئة (vs للهيئة), and stemming those two is
  not free: موردين → `third party vendor` (11) would outscore `aramco` + `sacs` (10)
  and pull "إطار SACS الخاص بأرامكو للموردين" away from `aramco-sacs-002`.
- **Prefix collisions.** الأطر ("frameworks", → `list frameworks`, 15) is a prefix
  of الأطراف ("parties"), so "الأطراف الثالثة" routes a third-party question to
  `frameworks-index` and outscores `third party` (11). The third-party case uses
  الموردين instead.
- **Bare Latin acronyms** score nothing on their own when every keyword for that
  entry is a multi-token phrase: "NCA" dropped into an Arabic question matches no
  `nca-overview` keyword, because they all read `who is nca`, `nca authority`, and
  similar. Convenient here (it keeps the comparison cases clean), but it means an
  Arabic question carrying only a Latin acronym relies entirely on that acronym
  appearing inside a longer keyword.

## Regression gate

`eval/baseline.json` holds the accepted metric values. `tests/Eval/RetrievalEvalTest.php`
fails CI if any headline metric drops below baseline. To accept a new (higher)
baseline, delete `eval/baseline.json`, run the Eval suite once to regenerate it,
review, and commit.
