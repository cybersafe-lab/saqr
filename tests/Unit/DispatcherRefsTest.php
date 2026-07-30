<?php
declare(strict_types=1);

use Saqr\Corpus;
use Saqr\GeneratorInterface;
use Saqr\Pipeline;
use Saqr\RateLimiter\InMemoryRateLimiter;

if (!function_exists('saqr_dispatch')) {
    require_once __DIR__ . '/../../bin/dispatcher.php';
}

/**
 * Stands in for a configured Generator: returns a fixed string that is already
 * valid sanitized HTML, so Pipeline takes the used_llm branch with no API key
 * and no network. What it returns does not matter to the tests below; that it
 * returns non-null does.
 */
final class FixedComparisonGenerator implements GeneratorInterface
{
    public const ANSWER = 'ECC is the floor for every Saudi entity. <strong>ISO 27001</strong> is the management system you build on top of it.';

    public function generate(string $question, array $contextEntries): ?string
    {
        return self::ANSWER;
    }
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

/**
 * A comparison answer has two forms. With an API key the generator synthesizes
 * it from every entry in `top`, and the union of their refs is what supports
 * it. With no key Pipeline falls back to top[0]'s answer verbatim, and the
 * other retrieved entries contributed nothing to the text a client reads, so
 * merging their refs would credit documents the answer never used.
 *
 * Both branches are covered: this test with no generator, the next one with a
 * canned GeneratorInterface. A live-key test is not something CI can run.
 */
test('compare serves only the echoed entry refs when no generator answered', function () {
    [$pipeline, $corpus] = dispatcherTestPipeline();
    $out = saqr_dispatch('compare', ['framework_a' => 'NCA ECC', 'framework_b' => 'ISO 27001'], $pipeline, $corpus);

    expect($out)->toHaveKeys(['comparison', 'used_llm', 'sources', 'refs'])
        ->and($out['used_llm'])->toBeFalse()
        ->and($out['refs'])->toBeArray()->not->toBeEmpty()
        ->and($out['refs'][0])->toHaveKeys(['title', 'url']);

    // Same question, same retrieval: top[0] is the entry whose answer shipped.
    $top = $pipeline->ask("Compare NCA ECC and ISO 27001")['top'];
    expect($out['comparison'])->toBe($top[0]['answer'])
        ->and($out['refs'])->toBe($top[0]['refs']);

    // More than one entry was retrieved and their refs differ, so the narrowed
    // list is strictly shorter than the union. Without that the assertion above
    // would pass on a corpus where the branch does not matter.
    expect(count($top))->toBeGreaterThan(1)
        ->and(count($out['refs']))->toBeLessThan(count(saqr_merge_refs($top)));
});

test('compare unions the refs of every retrieved entry when a generator answered', function () {
    $corpus = Corpus::loadFromFile(__DIR__ . '/../../corpus/frameworks.json');
    $pipeline = new Pipeline($corpus, new FixedComparisonGenerator(), new InMemoryRateLimiter());
    $out = saqr_dispatch('compare', ['framework_a' => 'NCA ECC', 'framework_b' => 'ISO 27001'], $pipeline, $corpus);

    expect($out)->toHaveKeys(['comparison', 'used_llm', 'sources', 'refs'])
        ->and($out['used_llm'])->toBeTrue()
        ->and($out['comparison'])->toBe(FixedComparisonGenerator::ANSWER);

    // Same question, same retrieval: the synthesized answer drew on all of `top`.
    $top = $pipeline->ask("Compare NCA ECC and ISO 27001")['top'];
    expect($out['refs'])->toBe(saqr_merge_refs($top));

    // The union is strictly wider than what the fallback branch would have
    // served, so this cannot pass on a corpus where the branch does not matter.
    expect(count($top))->toBeGreaterThan(1)
        ->and(count($out['refs']))->toBeGreaterThan(count($top[0]['refs']));
});

test('saqr_merge_refs unions entry refs, dedupes by url, and keeps input order', function () {
    $merged = saqr_merge_refs([
        ['refs' => [['title' => 'NCA ECC', 'url' => 'https://nca.gov.sa/a']]],
        ['refs' => [
            ['title' => 'NCA ECC (again)', 'url' => 'https://nca.gov.sa/a'],
            ['title' => 'SAMA CSF', 'url' => 'https://rulebook.sama.gov.sa/b'],
        ]],
        ['refs' => []],
        [],
    ]);

    expect(array_column($merged, 'url'))->toBe(['https://nca.gov.sa/a', 'https://rulebook.sama.gov.sa/b'])
        ->and($merged[0]['title'])->toBe('NCA ECC');
});

test('explain_control refs default to an empty array when nothing matches', function () {
    [$pipeline, $corpus] = dispatcherTestPipeline();
    $out = saqr_dispatch('explain_control', ['control_ref' => 'zzzzz-no-such-control'], $pipeline, $corpus);

    expect($out['refs'])->toBe([]);
});
