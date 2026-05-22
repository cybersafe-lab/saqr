<?php
declare(strict_types=1);

require __DIR__ . '/dispatcher.php';

try {
    [$pipeline, $corpus] = saqr_build_pipeline();
    $cmd = $argv[2] ?? '';
    // CLI args come positional; build the cmd-specific args dict.
    $args = match ($cmd) {
        'search' => ['question' => $argv[3] ?? ''],
        'compare' => ['framework_a' => $argv[3] ?? '', 'framework_b' => $argv[4] ?? ''],
        'explain_control' => ['control_ref' => $argv[3] ?? ''],
        'show_corpus' => [],
        default => throw new RuntimeException("UNKNOWN_CMD: {$cmd}"),
    };
    $result = saqr_dispatch($cmd, $args, $pipeline, $corpus);
    fwrite(STDOUT, json_encode(['ok' => true, 'result' => $result], JSON_UNESCAPED_UNICODE) . "\n");
    exit(0);
} catch (Throwable $e) {
    $msg = $e->getMessage();
    $code = preg_match('/^([A-Z_]+):/', $msg, $m) ? $m[1] : 'INTERNAL';
    // SEC-004: never write trace; SEC-002: redact env-var paths from messages
    $msg = preg_replace('#/[A-Za-z0-9_\-./]+#', '<path>', $msg);
    fwrite(STDERR, json_encode(['ok' => false, 'code' => $code, 'error' => $msg]) . "\n");
    exit(1);
}
