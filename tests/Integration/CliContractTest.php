<?php
declare(strict_types=1);

/**
 * Tests bin/saqr-cli as a real subprocess. Both once and serve modes.
 * The TS wrapper depends on this contract — any change here means a
 * coordinated change in mcp/src/php-bridge.ts.
 */

function saqrCli(): string {
    return escapeshellarg(__DIR__ . '/../../bin/saqr-cli');
}

test('once show_corpus returns ok + entry_count', function () {
    $out = shell_exec('php ' . saqrCli() . ' once show_corpus 2>/dev/null');
    $resp = json_decode(trim($out), true);
    expect($resp['ok'])->toBeTrue();
    expect($resp['result']['entry_count'])->toBeInt()->toBeGreaterThanOrEqual(25);
});

test('once search returns results array with id+score+content', function () {
    $cmd = 'php ' . saqrCli() . ' once search ' . escapeshellarg('What is PDPL?');
    $out = shell_exec($cmd . ' 2>/dev/null');
    $resp = json_decode(trim($out), true);
    expect($resp['ok'])->toBeTrue();
    expect($resp['result']['results'])->toBeArray()->not->toBeEmpty();
    // 'id' and 'score' MAY be null on production corpus entries (they don't have those fields).
    // Just assert the keys exist on the first result.
    expect($resp['result']['results'][0])->toHaveKeys(['id', 'score', 'content']);
});

test('once with unknown cmd exits non-zero', function () {
    exec('php ' . saqrCli() . ' once nonexistent 2>/dev/null', $out, $rc);
    expect($rc)->not->toBe(0);
});

test('serve mode echoes ids and handles multiple requests', function () {
    $cmd = 'printf %s ' . escapeshellarg(
        '{"id":"a","cmd":"show_corpus","args":{}}' . "\n" .
        '{"id":"b","cmd":"search","args":{"question":"PDPL"}}' . "\n"
    ) . ' | php ' . saqrCli() . ' serve 2>/dev/null';
    $out = shell_exec($cmd);
    $lines = array_values(array_filter(explode("\n", trim($out))));
    expect($lines)->toHaveCount(2);
    $r0 = json_decode($lines[0], true);
    $r1 = json_decode($lines[1], true);
    expect($r0['id'])->toBe('a');
    expect($r1['id'])->toBe('b');
    expect($r0['ok'])->toBeTrue();
    expect($r1['ok'])->toBeTrue();
});

test('serve mode invalid JSON gracefully responds with BAD_REQUEST', function () {
    $cmd = 'printf %s ' . escapeshellarg('not-json' . "\n") .
           ' | php ' . saqrCli() . ' serve 2>/dev/null';
    $out = shell_exec($cmd);
    $resp = json_decode(trim($out), true);
    expect($resp['ok'])->toBeFalse();
    expect($resp['code'])->toBe('BAD_REQUEST');
});

test('SAQR_CORPUS_PATH with .. is rejected', function () {
    $cmd = 'SAQR_CORPUS_PATH=/../etc/passwd php ' . saqrCli() . ' once show_corpus 2>&1';
    exec($cmd, $out, $rc);
    expect($rc)->not->toBe(0);
});
