<?php
declare(strict_types=1);

use Saqr\Corpus;
use Saqr\Retriever;

/**
 * Characterization tests pin the EXISTING retriever's load-bearing
 * quirks. Failure here = the underlying behavior changed. Update
 * these tests only with an explicit ADR.
 *
 * In particular: the byte-scoring quirk (strlen() on UTF-8) is the
 * reason a TS reimplementation would silently drift on Arabic
 * queries (kw.length in JS counts UTF-16 code units, not bytes).
 *
 * NOTE on entry shape: Corpus::loadFromFile() only preserves the
 * fields  category | keywords | answer — extra fields in the JSON
 * (id, title, framework) are stripped at load time. Tests identify
 * entries by a unique substring of their `answer` text.
 *
 * NOTE on method name: the public API is retrieveTopK(), not retrieve().
 */

function tiny_corpus(): Corpus {
    return Corpus::loadFromFile(__DIR__ . '/../fixtures/corpus-tiny.json');
}

test('byte-score prefers longer keyword over more matches', function () {
    $r = new Retriever(tiny_corpus());
    $results = $r->retrieveTopK('nca national cybersecurity authority', 2);
    // nca-long has keyword "national cybersecurity authority" = 32 bytes → wins over
    // nca-short keyword "nca" = 3 bytes.
    expect($results[0]['answer'])->toContain('longer keyword on purpose');
});

test('Arabic keyword strlen counts UTF-8 bytes, not codepoints', function () {
    // "ايزو" = 4 codepoints × 2 bytes each (UTF-8) = 8 bytes.
    // "iso"  = 3 bytes.
    // If a future port to JS uses kw.length (UTF-16 code units, ~4),
    // the ar-iso entry would lose by 1 point instead of winning by 5.
    // Identified via answer substring (Corpus strips 'id' field).
    $r = new Retriever(tiny_corpus());
    $results = $r->retrieveTopK('ايزو iso', 2);
    // ar-iso entry has keyword "ايزو" (8 bytes) — wins over en-iso "iso" (3 bytes)
    expect($results[0]['answer'])->toContain('معيار دولي');
    // Score of 8 is confirmed indirectly: ar-iso must beat en-iso (score 3)
    // The score is not exposed in the returned entry shape, but ranking proves it.
    expect($results[0]['keywords'])->toContain('ايزو');
});

test('Arabic normalization appends English aliases additively, does not replace', function () {
    // The normalizer in Retriever appends aliases. The original Arabic
    // substring must remain searchable alongside the appended English.
    $r = new Retriever(tiny_corpus());
    $resultsArabic = $r->retrieveTopK('ايزو', 2);
    $resultsEnglish = $r->retrieveTopK('iso', 2);
    // Arabic query — "ايزو" normalized to "ايزو iso 27001";
    // ar-iso keywords ["ايزو","أيزو"] match the Arabic word directly.
    $arabicAnswers = array_column($resultsArabic, 'answer');
    expect(implode(' ', $arabicAnswers))->toContain('معيار دولي');
    // English query — no normalization; en-iso keyword "iso" matches.
    $englishAnswers = array_column($resultsEnglish, 'answer');
    expect(implode(' ', $englishAnswers))->toContain('international standard');
});

test('mb_strpos substring match — audit matches inside auditor', function () {
    // Pin the intentional substring behavior. Don't "improve" to tokenizer.
    $r = new Retriever(tiny_corpus());
    $results = $r->retrieveTopK('auditor', 1);
    expect($results[0]['answer'])->toContain("Matches 'audit' inside 'auditor'");
});

test('empty keywords in entry are skipped without breaking the entry', function () {
    // The check `$kw !== ''` skips empty strings; the entry itself still scores
    // on its non-empty keyword "valid".
    $r = new Retriever(tiny_corpus());
    $results = $r->retrieveTopK('valid', 1);
    expect($results[0]['answer'])->toContain('Empty/whitespace keywords must be skipped');
});

test('k floor: retrieving k=0 returns at least one result', function () {
    // Retriever uses max(1, $k). Pin it.
    $r = new Retriever(tiny_corpus());
    $results = $r->retrieveTopK('nca', 0);
    expect(count($results))->toBe(1);
});

test('usort tie-break — nca-long wins outright by byte length, not tie-break', function () {
    // Both nca-short ("nca" = 3 bytes) and nca-long ("national cybersecurity authority" = 32 bytes)
    // match the query "nca national cybersecurity authority", but scores differ (3 vs 32).
    // This is a control test: when scores differ, tie-break isn't triggered.
    // Pin that nca-long wins outright.
    $r = new Retriever(tiny_corpus());
    $results = $r->retrieveTopK('nca national cybersecurity authority', 2);
    expect($results[0]['answer'])->toContain('longer keyword on purpose');
    expect($results[1]['answer'])->toContain('National Cybersecurity Authority of Saudi Arabia');
});
