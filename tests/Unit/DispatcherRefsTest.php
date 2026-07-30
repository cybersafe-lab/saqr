<?php
declare(strict_types=1);

use Saqr\Corpus;
use Saqr\Pipeline;
use Saqr\RateLimiter\InMemoryRateLimiter;

if (!function_exists('saqr_dispatch')) {
    require_once __DIR__ . '/../../bin/dispatcher.php';
}

/**
 * The MCP result shape is a client-facing contract: every search hit and every
 * explain_control answer must carry the official-source refs a GRC reader needs
 * to verify the claim. `score` used to be declared here and was always null —
 * Retriever never puts a score on the returned entry — so it is gone.
 */
function dispatcherTestPipeline(): array {
    $corpus = Corpus::loadFromFile(__DIR__ . '/../../corpus/frameworks.json');
    return [new Pipeline($corpus, null, new InMemoryRateLimiter()), $corpus];
}

test('search results include refs and omit the always-null score', function () {
    [$pipeline, $corpus] = dispatcherTestPipeline();
    $out = saqr_dispatch('search', ['question' => 'What is the NCA ECC?'], $pipeline, $corpus);

    expect($out['results'])->not->toBeEmpty();
    $first = $out['results'][0];
    expect($first)->toHaveKeys(['id', 'title', 'framework', 'content', 'refs'])
        ->and($first)->not->toHaveKey('score')
        ->and($first['refs'][0])->toHaveKeys(['title', 'url']);
});

test('every search hit carries a non-empty framework and refs list', function () {
    [$pipeline, $corpus] = dispatcherTestPipeline();
    $out = saqr_dispatch('search', ['question' => 'PDPL data subject rights'], $pipeline, $corpus);

    expect($out['results'])->not->toBeEmpty();
    foreach ($out['results'] as $hit) {
        expect($hit['framework'])->toBeString()->not->toBeEmpty();
        expect($hit['refs'])->toBeArray()->not->toBeEmpty();
    }
});

test('explain_control surfaces the refs of the entry it summarized', function () {
    [$pipeline, $corpus] = dispatcherTestPipeline();
    $out = saqr_dispatch('explain_control', ['control_ref' => 'ECC-2-3-1'], $pipeline, $corpus);

    expect($out)->toHaveKeys(['control_id', 'framework', 'summary', 'refs', 'sources']);
    expect($out['refs'])->toBeArray()->not->toBeEmpty();
    expect($out['refs'][0])->toHaveKeys(['title', 'url']);
});

test('explain_control refs default to an empty array when nothing matches', function () {
    [$pipeline, $corpus] = dispatcherTestPipeline();
    $out = saqr_dispatch('explain_control', ['control_ref' => 'zzzzz-no-such-control'], $pipeline, $corpus);

    expect($out['refs'])->toBe([]);
});
