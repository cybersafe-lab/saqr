<?php
declare(strict_types=1);

use Saqr\Corpus;
use Saqr\Pipeline;
use Saqr\RateLimiter\InMemoryRateLimiter;

if (!function_exists('saqr_dispatch')) {
    require_once __DIR__ . '/../../bin/dispatcher.php';
}

/**
 * The MCP result shape is a client-facing contract: an answer drawn from a
 * regulatory entry must carry the official-source refs a GRC reader needs to
 * verify it. The `refs` key is always present; it is empty only for the META
 * entries, which bin/corpus-lint exempts because they describe the assistant
 * rather than a framework. `score` used to be declared here and was always
 * null — Retriever never puts a score on the returned entry — so it is gone.
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

/**
 * `title` used to fall back to `category` when an entry had none, and no entry
 * had one, so every MCP result was labeled with its bucket ("CST / ARAMCO /
 * PDPL"). Titles are populated now and corpus-lint requires them, so the
 * fallback is gone: a category name reaching a client would mean the corpus
 * lost a title.
 */
test('search labels each hit with the entry title, never its category', function () {
    [$pipeline, $corpus] = dispatcherTestPipeline();
    $out = saqr_dispatch('search', ['question' => 'What is the NCA ECC?'], $pipeline, $corpus);

    $categories = [
        'AUTHORITIES', 'NCA FRAMEWORKS', 'SAMA FRAMEWORKS',
        'CST / ARAMCO / PDPL', 'COMPARISONS', 'META',
    ];
    expect($out['results'])->not->toBeEmpty();
    foreach ($out['results'] as $hit) {
        expect($hit['title'])->toBeString()->not->toBeEmpty();
        expect(in_array($hit['title'], $categories, true))->toBeFalse($hit['title']);
    }
});

test('non-META hits for a regulatory query carry a framework and at least one ref', function () {
    [$pipeline, $corpus] = dispatcherTestPipeline();
    $out = saqr_dispatch('search', ['question' => 'PDPL data subject rights'], $pipeline, $corpus);

    expect($out['results'])->not->toBeEmpty();
    $checked = 0;
    foreach ($out['results'] as $hit) {
        expect($hit['framework'])->toBeString()->not->toBeEmpty();
        if ($hit['framework'] === 'META') {
            continue; // exempt from refs by bin/corpus-lint
        }
        expect($hit['refs'])->toBeArray()->not->toBeEmpty();
        $checked++;
    }
    expect($checked)->toBeGreaterThan(0);
});

test('a META hit serves refs as an empty array, not a missing key', function () {
    [$pipeline, $corpus] = dispatcherTestPipeline();
    $out = saqr_dispatch('search', ['question' => 'help what frameworks do you know'], $pipeline, $corpus);

    $meta = array_values(array_filter($out['results'], static fn($h) => $h['framework'] === 'META'));
    expect($meta)->not->toBeEmpty();
    expect($meta[0])->toHaveKey('refs')
        ->and($meta[0]['refs'])->toBe([]);
});

test('explain_control surfaces the refs of the entry it summarized', function () {
    [$pipeline, $corpus] = dispatcherTestPipeline();
    $out = saqr_dispatch('explain_control', ['control_ref' => 'ECC-2-3-1'], $pipeline, $corpus);

    expect($out)->toHaveKeys(['control_id', 'framework', 'summary', 'refs', 'sources']);
    expect($out['refs'])->toBeArray()->not->toBeEmpty();
    expect($out['refs'][0])->toHaveKeys(['title', 'url']);
});

test('compare unions the refs of every entry it drew on, deduped by url', function () {
    [$pipeline, $corpus] = dispatcherTestPipeline();
    $out = saqr_dispatch('compare', ['framework_a' => 'NCA ECC', 'framework_b' => 'ISO 27001'], $pipeline, $corpus);

    expect($out)->toHaveKeys(['comparison', 'used_llm', 'sources', 'refs']);
    expect($out['refs'])->toBeArray()->not->toBeEmpty();
    expect($out['refs'][0])->toHaveKeys(['title', 'url']);

    $urls = array_column($out['refs'], 'url');
    expect($urls)->toBe(array_values(array_unique($urls)));
});

test('explain_control refs default to an empty array when nothing matches', function () {
    [$pipeline, $corpus] = dispatcherTestPipeline();
    $out = saqr_dispatch('explain_control', ['control_ref' => 'zzzzz-no-such-control'], $pipeline, $corpus);

    expect($out['refs'])->toBe([]);
});
