# Saqr retrieval eval

Saqr's answer quality is measured against a labeled question set, run on every
PR. The runner loads the production corpus (`corpus/frameworks.json`) and calls
the same `Retriever` that production serves, so there is no separate retrieval
implementation to drift.

## Metrics

- **precision@1** — an expected id is the top result.
- **precision@3** — an expected id is within the top 3.
- **recall@3** — fraction of expected ids retrieved within the top 3.
- **MRR** — mean reciprocal rank of the first matching id.

## Current results

| Scope | p@1 | p@3 | recall@3 | MRR | n |
|-------|-----|-----|----------|-----|---|
| Overall | 0.931 | 0.958 | 0.958 | 0.944 | 72 |
| en | 0.969 | 1.000 | 1.000 | 0.984 | 64 |
| ar | 0.625 | 0.625 | 0.625 | 0.625 | 8 |

Regenerate with `php bin/saqr-eval`.

## Adding a question

Append a line to `eval/questions.jsonl`:
`{"q": "...", "lang": "en|ar", "expected_ids": ["<real-corpus-id>"], "note": "..."}`
Every `expected_id` must exist in the corpus (the runner errors otherwise).

## Regression gate

`eval/baseline.json` holds the accepted metric values. `tests/Eval/RetrievalEvalTest.php`
fails CI if any headline metric drops below baseline. To accept a new (higher)
baseline, delete `eval/baseline.json`, run the Eval suite once to regenerate it,
review, and commit.
