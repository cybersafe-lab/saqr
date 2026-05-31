<?php
declare(strict_types=1);

use Saqr\Eval\Metrics;
use Saqr\Corpus;
use Saqr\Retriever;

test('hit@1 counts a hit when an expected id is the top result', function () {
    $m = new Metrics();
    expect($m->hitAtK(['a', 'b', 'c'], ['a'], 1))->toBe(1.0);
    expect($m->hitAtK(['b', 'a', 'c'], ['a'], 1))->toBe(0.0);
});

test('hit@3 counts a hit when an expected id is in the top 3', function () {
    $m = new Metrics();
    expect($m->hitAtK(['x', 'y', 'a'], ['a'], 3))->toBe(1.0);
    expect($m->hitAtK(['x', 'y', 'z'], ['a'], 3))->toBe(0.0);
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

/** @return array{hit1: float, hit3: float, mrr: float, n: int} */
function runEvalAggregate(): array {
    $corpus = Corpus::loadFromFile(__DIR__ . '/../../corpus/frameworks.json');
    $known = array_flip(array_filter(array_column($corpus->all(), 'id')));
    $retriever = new Retriever($corpus);
    $m = new Metrics();

    $rows = array_filter(array_map(
        'trim',
        file(__DIR__ . '/../../eval/questions.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)
    ));

    $hit1 = $hit3 = $mrr = 0.0;
    $n = 0;
    foreach ($rows as $line) {
        $row = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        if (!isset($row['q'], $row['expected_ids']) || !is_array($row['expected_ids'])) {
            throw new RuntimeException("eval question malformed (missing q/expected_ids): {$line}");
        }
        foreach ($row['expected_ids'] as $id) {
            if (!isset($known[$id])) {
                throw new RuntimeException("eval question references unknown id: {$id}");
            }
        }
        $ranked = array_values(array_filter(array_map(
            static fn ($e) => $e['id'] ?? null,
            $retriever->retrieveTopK($row['q'], 3)
        )));
        $hit1 += $m->hitAtK($ranked, $row['expected_ids'], 1);
        $hit3 += $m->hitAtK($ranked, $row['expected_ids'], 3);
        $mrr  += $m->reciprocalRank($ranked, $row['expected_ids']);
        $n++;
    }
    if ($n === 0) { throw new RuntimeException('eval/questions.jsonl has no usable questions'); }
    return [
        'hit1' => round($hit1 / $n, 4),
        'hit3' => round($hit3 / $n, 4),
        'mrr'  => round($mrr / $n, 4),
        'n'    => $n,
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
    foreach (['hit1', 'hit3', 'mrr'] as $metric) {
        expect($agg[$metric])->toBeGreaterThanOrEqual(
            $base[$metric] - $eps,
            "Metric {$metric} regressed: {$agg[$metric]} < baseline {$base[$metric]}"
        );
    }
});
