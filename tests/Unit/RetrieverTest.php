<?php
declare(strict_types=1);

use Saqr\Corpus;
use Saqr\Retriever;

$cases = json_decode(file_get_contents(__DIR__ . '/../fixtures/retrieval-cases.json'), true);

dataset('retrieval_cases', array_map(
    fn($c) => [$c['question'], $c['expected_top1_id_in'], $c['expected_min_score']],
    $cases
));

test('top-1 ranked correctly for fixture question', function (string $q, array $expected, int $minScore) {
    $corpus = Corpus::loadFromFile(__DIR__ . '/../fixtures/corpus-tiny.json');
    $r = new Retriever($corpus);
    $results = $r->retrieveTopK($q, 3);
    if ($expected === []) {
        expect($results)->toBeEmpty();
        return;
    }
    expect($results)->not->toBeEmpty();
    // Corpus preserves 'id' on all entries (may be null if not supplied).
    expect($expected)->toContain($results[0]['id']);
})->with('retrieval_cases');

test('Arabic critical-systems phrase retrieves nca-cscc (official acronym)', function () {
    $corpus = Corpus::loadFromFile(__DIR__ . '/../../corpus/frameworks.json');
    $r = new Retriever($corpus);
    $ids = array_column($r->retrieveTopK('ما هي ضوابط الأنظمة الحساسة؟', 3), 'id');
    expect($ids[0])->toBe('nca-cscc');
});

test('cloud cybersecurity controls question retrieves nca-ccc (official acronym)', function () {
    $corpus = Corpus::loadFromFile(__DIR__ . '/../../corpus/frameworks.json');
    $r = new Retriever($corpus);
    $ids = array_column($r->retrieveTopK('What are the NCA cloud cybersecurity controls?', 3), 'id');
    expect($ids[0])->toBe('nca-ccc');
});

test('ordinary Arabic ISO questions are not misrouted to the comparison entry', function () {
    // Regression pin: the iso-vs-nca alias was keyed on 'استخدام أيزو' ("using
    // ISO"), which fired on any question about using ISO 27001. The comparison
    // intent lives in "لتلبية متطلبات" (does ISO satisfy the other framework),
    // not in "استخدام".
    $corpus = Corpus::loadFromFile(__DIR__ . '/../../corpus/frameworks.json');
    $r = new Retriever($corpus);

    // "how do I start using ISO 27001 in my company" is a where-to-start
    // question, so program-starting-point winning here is correct; what must
    // not happen is the comparison entry taking the slot.
    $ids = array_column($r->retrieveTopK('كيف أبدأ استخدام أيزو 27001 في شركتي؟', 3), 'id');
    expect($ids[0])->toBe('program-starting-point');

    $ids = array_column($r->retrieveTopK('ما هي فوائد استخدام أيزو 27001 لتحسين الأمن؟', 3), 'id');
    expect($ids[0])->toBe('iso-27001');

    // The substitution question still reaches the comparison entry.
    $ids = array_column($r->retrieveTopK('هل يمكنني استخدام أيزو 27001 لتلبية متطلبات هيئة الأمن السيبراني؟', 3), 'id');
    expect($ids[0])->toBe('iso-vs-nca');
});

test('generic ECC-compliance question is not misrouted to a comparison entry', function () {
    // Regression pin: ecc-vs-ccc/ecc-vs-cscc previously carried a generic
    // "comply with ecc" style keyword that fired on any ECC question,
    // displacing nca-ecc and program-starting-point as top-1.
    $corpus = Corpus::loadFromFile(__DIR__ . '/../../corpus/frameworks.json');
    $r = new Retriever($corpus);

    $ids = array_column($r->retrieveTopK('Do I need to comply with ECC?', 3), 'id');
    expect($ids[0])->toBe('nca-ecc');

    $ids = array_column($r->retrieveTopK("I need to comply with ECC, where do I start?", 3), 'id');
    expect($ids[0])->toBe('program-starting-point');
});

test('Arabic orthographic variants reach the same entry as the canonical spelling', function () {
    // Real user query, 2026-07-30: "وش قانون حمايه البيانات" returned nothing.
    // حمايه is spelled with a final heh where every alias key writes حماية with
    // a taa marbuta, so substring matching failed — a whole class of spellings,
    // not one alias. Folding runs on both sides of the comparison.
    $corpus = Corpus::loadFromFile(__DIR__ . '/../../corpus/frameworks.json');
    $r = new Retriever($corpus);

    $top = fn (string $q) => array_column($r->retrieveTopK($q, 3), 'id')[0] ?? '(no match)';

    expect($top('وش قانون حمايه البيانات'))->toBe('pdpl');
    expect($top('ما هو قانون حماية البيانات؟'))->toBe('pdpl');

    // The fold is not PDPL-specific: any key carrying a taa marbuta matches its
    // heh spelling, and an alef maqsura written as a yaa (or the reverse) folds
    // in the same pass.
    expect($top('ما هي الهيئه الوطنيه للأمن السيبراني؟'))->toBe('nca-overview');
    expect($top('ما هي الهيئه الوطنيه للأمن السيبرانى؟'))->toBe('nca-overview');
    expect($top('ما هو مستوي النضج المطلوب؟'))->toBe('maturity');

    // The bare حماية البيانات stem must not drag data-control questions off the
    // DCC entry, nor unseat the PDPL/DCC comparison.
    expect($top('ما هي ضوابط البيانات؟'))->toBe('nca-dcc');
    expect($top('ما العلاقة بين نظام حماية البيانات الشخصية وضوابط البيانات؟'))->toBe('pdpl-vs-dcc');
});
