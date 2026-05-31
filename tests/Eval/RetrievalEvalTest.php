<?php
declare(strict_types=1);

use Saqr\Eval\Metrics;
use Saqr\Corpus;
use Saqr\Retriever;

test('precision@1 counts a hit when an expected id is the top result', function () {
    $m = new Metrics();
    expect($m->precisionAtK(['a', 'b', 'c'], ['a'], 1))->toBe(1.0);
    expect($m->precisionAtK(['b', 'a', 'c'], ['a'], 1))->toBe(0.0);
});

test('precision@3 counts a hit when an expected id is in the top 3', function () {
    $m = new Metrics();
    expect($m->precisionAtK(['x', 'y', 'a'], ['a'], 3))->toBe(1.0);
    expect($m->precisionAtK(['x', 'y', 'z'], ['a'], 3))->toBe(0.0);
});

test('recall@3 is the fraction of expected ids found in top 3', function () {
    $m = new Metrics();
    expect($m->recallAtK(['a', 'b', 'x'], ['a', 'b'], 3))->toBe(1.0);
    expect($m->recallAtK(['a', 'x', 'y'], ['a', 'b'], 3))->toBe(0.5);
});

test('MRR is the reciprocal rank of the first matching id', function () {
    $m = new Metrics();
    expect($m->reciprocalRank(['x', 'a', 'y'], ['a']))->toBe(0.5);
    expect($m->reciprocalRank(['a', 'x'], ['a']))->toBe(1.0);
    expect($m->reciprocalRank(['x', 'y'], ['a']))->toBe(0.0);
});

/** @return array{p1: float, p3: float, r3: float, mrr: float, n: int} */
function runEvalAggregate(): array {
    $corpus = Corpus::loadFromFile(__DIR__ . '/../../corpus/frameworks.json');
    $known = array_flip(array_filter(array_column($corpus->all(), 'id')));
    $retriever = new Retriever($corpus);
    $m = new Metrics();

    $rows = array_filter(array_map(
        'trim',
        file(__DIR__ . '/../../eval/questions.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)
    ));

    $p1 = $p3 = $r3 = $mrr = 0.0;
    $n = 0;
    foreach ($rows as $line) {
        $row = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        foreach ($row['expected_ids'] as $id) {
            if (!isset($known[$id])) {
                throw new RuntimeException("eval question references unknown id: {$id}");
            }
        }
        $ranked = array_values(array_filter(array_map(
            static fn ($e) => $e['id'] ?? null,
            $retriever->retrieveTopK($row['q'], 3)
        )));
        $p1  += $m->precisionAtK($ranked, $row['expected_ids'], 1);
        $p3  += $m->precisionAtK($ranked, $row['expected_ids'], 3);
        $r3  += $m->recallAtK($ranked, $row['expected_ids'], 3);
        $mrr += $m->reciprocalRank($ranked, $row['expected_ids']);
        $n++;
    }
    return [
        'p1'  => round($p1 / $n, 4),
        'p3'  => round($p3 / $n, 4),
        'r3'  => round($r3 / $n, 4),
        'mrr' => round($mrr / $n, 4),
        'n'   => $n,
    ];
}

test('retrieval quality does not regress below the committed baseline', function () {
    $agg = runEvalAggregate();
    $baselinePath = __DIR__ . '/../../eval/baseline.json';

    if (!is_file($baselinePath)) {
        file_put_contents($baselinePath, json_encode($agg, JSON_PRETTY_PRINT) . "\n");
        $this->markTestSkipped('Baseline created on first run; review eval/baseline.json and rerun.');
        return;
    }

    $base = json_decode((string) file_get_contents($baselinePath), true);
    $eps = 0.001;
    foreach (['p1', 'p3', 'r3', 'mrr'] as $metric) {
        expect($agg[$metric])->toBeGreaterThanOrEqual(
            $base[$metric] - $eps,
            "Metric {$metric} regressed: {$agg[$metric]} < baseline {$base[$metric]}"
        );
    }
});
