<?php
declare(strict_types=1);

use Saqr\Corpus;
use Saqr\Pipeline;
use Saqr\Generator;
use Saqr\RateLimiter\InMemoryRateLimiter;

/**
 * Build a Pipeline once. Reused by both `once` and `serve`.
 *
 * @return array{0: Pipeline, 1: Corpus}
 */
function saqr_build_pipeline(): array {
    $corpus_path = getenv('SAQR_CORPUS_PATH') ?: (string) realpath(__DIR__ . '/../corpus/frameworks.json');
    saqr_validate_corpus_path($corpus_path); // SEC-002

    $corpus = Corpus::loadFromFile($corpus_path);
    $api_key = getenv('ANTHROPIC_API_KEY') ?: null;
    $generator = $api_key ? new Generator($api_key) : null;
    $rate_limiter = new InMemoryRateLimiter();
    return [new Pipeline($corpus, $generator, $rate_limiter), $corpus];
}

/**
 * SEC-002: reject env var values that look like exploitation attempts.
 * Absolute path, no NUL bytes, no relative ".." segments.
 */
function saqr_validate_corpus_path(string $path): void {
    if ($path === '' || strpos($path, "\0") !== false) {
        throw new RuntimeException('SAQR_CORPUS_PATH contains an invalid character');
    }
    if (strpos($path, '..') !== false) {
        throw new RuntimeException('SAQR_CORPUS_PATH must not contain ".."');
    }
    if ($path[0] !== '/' && !preg_match('#^[A-Z]:[\\\\/]#', $path)) {
        throw new RuntimeException('SAQR_CORPUS_PATH must be absolute');
    }
}

/**
 * Translate a Pipeline result into the documented CLI result shape.
 * The spec §3.3 contract: `result` is one of four shapes, keyed on cmd.
 */
function saqr_dispatch(string $cmd, array $args, Pipeline $pipeline, Corpus $corpus): array {
    switch ($cmd) {
        case 'search':
            $q = $args['question'] ?? '';
            $r = $pipeline->ask($q);
            if (!$r['ok']) {
                return ['results' => [], 'query_normalized' => $r['query_normalized'] ?? $q];
            }
            return [
                'results' => array_map(static fn($t) => [
                    'id' => $t['id'] ?? null,
                    'title' => $t['title'] ?? $t['category'] ?? null,
                    'score' => $t['score'] ?? null,
                    'framework' => $t['framework'] ?? null,
                    'content' => $t['answer'] ?? '',
                ], $r['top']),
                'query_normalized' => $r['query_normalized'] ?? $q,
            ];

        case 'compare':
            $a = $args['framework_a'] ?? '';
            $b = $args['framework_b'] ?? '';
            $r = $pipeline->ask("Compare {$a} and {$b}");
            return [
                'comparison' => $r['answer'] ?? '',
                'used_llm' => (bool)($r['used_llm'] ?? false),
                'sources' => array_map(static fn($t) => $t['id'] ?? $t['category'] ?? 'unknown', $r['top'] ?? []),
            ];

        case 'explain_control':
            $ref = $args['control_ref'] ?? '';
            $r = $pipeline->ask("Explain {$ref}");
            $first = $r['top'][0] ?? null;
            return [
                'control_id' => $ref,
                'framework' => $first['framework'] ?? null,
                'summary' => $first['answer'] ?? '',
                'sources' => array_map(static fn($t) => $t['id'] ?? $t['category'] ?? 'unknown', $r['top'] ?? []),
            ];

        case 'show_corpus':
            $entries = $corpus->all();
            $frameworks = array_values(array_unique(array_filter(
                array_map(static fn($e) => $e['framework'] ?? $e['category'] ?? null, $entries)
            )));
            return ['frameworks' => $frameworks, 'entry_count' => count($entries)];

        default:
            throw new RuntimeException("UNKNOWN_CMD: {$cmd}");
    }
}
