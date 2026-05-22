<?php
declare(strict_types=1);

use Saqr\Corpus;
use Saqr\Retriever;

test('ranking snapshot stable for all fixture cases', function () {
    $cases = json_decode(file_get_contents(__DIR__ . '/../fixtures/retrieval-cases.json'), true);
    $corpus = Corpus::loadFromFile(__DIR__ . '/../fixtures/corpus-tiny.json');
    $r = new Retriever($corpus);

    $actual = array_map(function ($c) use ($r) {
        $top = $r->retrieveTopK($c['question'], 3);
        return [
            'question' => $c['question'],
            'top3_ids' => array_map(static fn($t) => $t['id'] ?? null, $top),
        ];
    }, $cases);

    $snapPath = __DIR__ . '/__snapshots__/rankings.json';
    if (!is_file($snapPath)) {
        if (!is_dir(dirname($snapPath))) {
            mkdir(dirname($snapPath), 0755, true);
        }
        file_put_contents($snapPath, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $this->markTestSkipped('Snapshot created on first run; rerun to compare.');
        return;
    }
    $expected = json_decode(file_get_contents($snapPath), true);
    expect($actual)->toBe($expected);
});
