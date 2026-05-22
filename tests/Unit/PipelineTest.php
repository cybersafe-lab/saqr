<?php
declare(strict_types=1);

use Saqr\Corpus;
use Saqr\Pipeline;
use Saqr\RateLimiter\InMemoryRateLimiter;

function tinyPipelineNoLLM(): Pipeline {
    return new Pipeline(
        Corpus::loadFromFile(__DIR__ . '/../fixtures/corpus-tiny.json'),
        null,
        new InMemoryRateLimiter()
    );
}

test('no API key configured → returns top1 verbatim (no LLM)', function () {
    $r = tinyPipelineNoLLM()->ask('What is the NCA?');
    expect($r['ok'])->toBeTrue();
    expect($r['used_llm'])->toBeFalse();
    expect($r['answer'])->not->toBeEmpty();
});

test('question over 500 chars is truncated, not rejected', function () {
    $q = str_repeat('audit ', 200); // ~1200 chars
    $r = tinyPipelineNoLLM()->ask($q);
    expect($r['ok'])->toBeTrue();
});

test('empty top returns ok=true with no_match reason', function () {
    $r = tinyPipelineNoLLM()->ask('completely-unrelated-query-zzzzz');
    expect($r['ok'])->toBeTrue();
    expect($r['reason'] ?? null)->toBe('no_match');
});
