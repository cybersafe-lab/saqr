<?php
declare(strict_types=1);

use Saqr\Util\StderrScrubber;

require __DIR__ . '/dispatcher.php';

// Load corpus + pipeline ONCE for the lifetime of this serve session.
try {
    [$pipeline, $corpus] = saqr_build_pipeline();
} catch (Throwable $e) {
    StderrScrubber::write(json_encode([
        'ok' => false,
        'code' => 'CORPUS_INVALID',
        'error' => $e->getMessage(),
    ]) . "\n");
    exit(1);
}

// Tell stdout to flush each line immediately.
stream_set_write_buffer(STDOUT, 0);

// NDJSON request loop. One JSON object per line on stdin.
while (($line = fgets(STDIN)) !== false) {
    $line = trim($line);
    if ($line === '') {
        continue;
    }
    $req = json_decode($line, true);
    $id = (is_array($req) && isset($req['id'])) ? $req['id'] : null;

    try {
        if (!is_array($req)) {
            throw new RuntimeException('BAD_REQUEST: not a JSON object');
        }
        $cmd = $req['cmd'] ?? '';
        $args = $req['args'] ?? [];
        if (!is_string($cmd) || $cmd === '') {
            throw new RuntimeException('BAD_REQUEST: missing cmd');
        }
        if (!is_array($args)) {
            throw new RuntimeException('BAD_REQUEST: args must be object');
        }
        $result = saqr_dispatch($cmd, $args, $pipeline, $corpus);
        fwrite(STDOUT, json_encode([
            'id' => $id,
            'ok' => true,
            'result' => $result,
        ], JSON_UNESCAPED_UNICODE) . "\n");
    } catch (Throwable $e) {
        $msg = $e->getMessage();
        $code = preg_match('/^([A-Z_]+):/', $msg, $m) ? $m[1] : 'INTERNAL';
        $msg = preg_replace('#/[A-Za-z0-9_\-./]+#', '<path>', $msg);
        fwrite(STDOUT, json_encode([
            'id' => $id,
            'ok' => false,
            'code' => $code,
            'error' => $msg,
        ]) . "\n");
    }
}
