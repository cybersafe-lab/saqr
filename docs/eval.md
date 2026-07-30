# Saqr retrieval eval

Saqr's answer quality is measured against a labeled question set, run on every
PR. The runner loads the production corpus (`corpus/frameworks.json`) and calls
the same `Retriever` that production serves, so there is no separate retrieval
implementation to drift.

## Metrics

- **hit@1** — a gold id is the top result.
- **hit@3** — a gold id is within the top 3.
- **MRR** — mean reciprocal rank of the first matching id.

## Current results (2026-07-30)

| Scope | hit@1 | hit@3 | MRR | n |
|-------|-------|-------|-----|---|
| Overall | 0.963 | 0.978 | 0.970 | 135 |
| en | 0.956 | 0.985 | 0.971 | 68 |
| ar | 0.970 | 0.970 | 0.970 | 67 |

Regenerate with `php bin/saqr-eval`.

Arabic coverage is 67 cases over all 32 corpus ids, in practitioner MSA and in
Saudi colloquial: bare topics, mixed Arabic with Latin acronyms, Arabic
transliterations of those acronyms, Arabic-Indic digits, diacritics, tatweel,
observed misspellings, and the definite article assimilated after لـ. Read the
`ar` number for what it is: corpus keywords are English, so an Arabic question
only retrieves when `Retriever` recognizes one of its phrasings and appends the
matching English keywords. `ar` hit@1 therefore measures **alias coverage of the
phrasings in this set**, not open-domain Arabic understanding. Phrasings the map
has not seen score zero and return nothing, which is why the misses below are worth
more attention than the headline figure.

## Matching pipeline

Retrieval scores against a normalized *copy* of the question; the question the
generator sees is never touched. `Retriever::normalize()` runs, in order: NFKC
(when ext-intl is present), lowercasing, removal of zero-width characters and
tatweel, removal of Arabic combining marks, Arabic-Indic and Extended
Arabic-Indic digit folding, the alef/alef-maqsura/taa-marbuta letter folds,
Unicode dash folding, punctuation and whitespace collapsing to single ASCII
spaces, and finally a narrow canonicalization of two identifiers that the corpus
spells one way and users spell four (`ISO 27001`, `SACS-210` / `SACS-002`). The
same pipeline runs once over every Arabic alias key, so a key is written in its
canonical spelling and never needs a hand-maintained variant.

Two folds are deliberately **not** applied. `ؤ→و` and `ئ→ي` merge distinct
spellings without repairing the deletion typos that motivate them; observed words
get their own alias key instead. The definite article is never stripped: ال is
two of the most common letters in the language, and removing it turns short stems
into broad false positives. Clitics are handled by curated stems.

## Adding a question

Append a line to `eval/questions.jsonl`:
`{"q": "...", "lang": "en|ar", "expected_ids": ["<real-corpus-id>"], "note": "..."}`
Every `expected_id` must exist in the corpus (the runner errors otherwise).

## Known limitations

Five cases in the set are known misses: two Arabic, three English. All five are
properties of the scoring model, not gaps in the alias map, so they are recorded
rather than patched. Scores below are keyword byte lengths, the retriever's unit
of relevance.

- **Arabic comparison questions that name ECC in Arabic** (`ecc-vs-ccc`,
  `ecc-vs-cscc`). "ما الفرق بين الضوابط الأساسية وضوابط الحوسبة السحابية؟" resolves
  to `nca-ecc` (42) and `nca-ccc` (35); `ecc-vs-ccc` scores 0. The alias for
  الضوابط الأساسية emits three `nca-ecc` keywords, and a comparison entry's
  keywords are short acronym pairs (`ecc vs ccc` = 10, `ecc and ccc` = 11), so the
  entry answering the question cannot outweigh the two entries it compares. The
  comparison entries whose counterparts are lighter do resolve correctly in Arabic
  (`sama-vs-nca` beats 11, `aramco-vs-nca` beats 10, `pdpl-vs-dcc` beats 7,
  `iso-vs-nca` beats 14). Fixing the ECC pairs would need comparison-intent
  detection, or Arabic keywords on the comparison entries themselves. The
  transliterated form of the same comparison ("وش الفرق بين اي سي سي وسي سي سي")
  *does* resolve, because an acronym transliteration emits only the acronym
  keywords: naming both sides then leaves the comparison entry (21) ahead of
  `nca-ecc` (10) and `nca-ccc` (7). That is the shape a general fix would need.
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

Arabic-specific traps found while triaging. They are worth knowing before adding
aliases, since substring matching has no notion of word boundaries:

- **Definite-article assimilation.** A key beginning with ال never matches the
  لل form: للحوسبة does not contain الحوسبة, and للتدقيق does not contain التدقيق.
  Curated stems carry these (حوسبة السحابية, تدقيق, ضوابط الأساسية, إطار التنظيمي,
  تقنية التشغيلية). Stemming is not free: موردين → `third party vendor` (11) would
  outscore `aramco` + `sacs` (10) and pull "إطار SACS الخاص بأرامكو للموردين" away
  from `aramco-sacs-002`, so الموردين is deliberately left unstemmed and للموردين
  on its own retrieves nothing. الهيئة is stemmed only in the full official title
  (هيئة الوطنية للأمن السيبراني): stemming the truncated الهيئة الوطنية makes the
  authority entry fire inside "وضوابط الأمن السيبراني للهيئة الوطنية" and displace
  the ISO/NCA comparison that question is actually asking for. The same trap is
  live for البنك المركزي السعودي (vs للبنك المركزي السعودي).
- **Prefix collisions.** الأطر ("frameworks", → `list frameworks`, 15) is a prefix
  of الأطراف ("parties"), so "الأطراف الثالثة" routes a third-party question to
  `frameworks-index` and outscores `third party` (11). The third-party case uses
  الموردين instead.
- **A bare topic stem belongs to whichever entry claimed it first.** حماية البيانات
  is PDPL's stem, so "وش ضوابط حماية البيانات عند NCA" returns `pdpl` even though
  the question names NCA and means `nca-dcc`. Left as is on purpose: a compound
  key for that one word order does not generalize, and PDPL is the closer answer
  to the question read without its trailing عند NCA. The general case needs
  intent detection, the same gap behind the comparison misses above.
- **Folds widen keys as well as questions.** أم ("or") folds to ام, which is also
  how أمس and أمانة start, so a comparison key ending in the bare disjunction
  ("مؤسسة النقد أم") fires inside ordinary sentences. Comparison keys name the
  alternative they compare against instead (مؤسسة النقد أم ضوابط / أم هيئة), which
  is the same rule the ISO comparison keys already followed.
- **Orthographic variants are handled by the pipeline, not by hand.** Do not add a
  variant key for a hamza, a taa marbuta written as heh, an alef maqsura written
  as yaa, a diacritic, a tatweel, or an Arabic-Indic digit; `Retriever::normalize`
  folds all of them on both sides of the comparison. Deletion and substitution
  typos (ظوابط, البيانت, الوظايف) are outside what folding can repair and get
  their own key, one per observed error.
- **Zero-width characters are removed, not replaced with a space.** That repairs
  the common corruption, an invisible character pasted *inside* a word, but a
  ZWSP used *instead of* a space joins two words and the alias misses.
- **Bare Latin acronyms** score nothing on their own when every keyword for that
  entry is a multi-token phrase: "NCA" dropped into an Arabic question matches no
  `nca-overview` keyword, because they all read `who is nca`, `nca authority`, and
  similar. Convenient here (it keeps the comparison cases clean), but it means an
  Arabic question carrying only a Latin acronym relies entirely on that acronym
  appearing inside a longer keyword. Two-letter acronyms are worse and are left
  out on purpose: "OT" as a keyword would match inside `not`, `other`, and
  `protection`, so "متطلبات حماية أنظمة OT" retrieves nothing.

## Regression gate

`eval/baseline.json` holds the accepted metric values. `tests/Eval/RetrievalEvalTest.php`
fails CI if any headline metric drops below baseline. To accept a new (higher)
baseline, delete `eval/baseline.json`, run the Eval suite once to regenerate it,
review, and commit.
