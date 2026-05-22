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
