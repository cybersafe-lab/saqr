<?php
declare(strict_types=1);

/**
 * bin/refs-check hits the network, so the happy path is a curation-time run,
 * not a test. What is testable, and what these cover, are the cases where it
 * must refuse before reaching curl: a corpus it cannot read, cannot parse, or
 * that yields no URL to check. Each of those used to fall through an empty
 * loop and exit 0, which reads as "every ref resolves" when nothing was tried.
 */

function runRefsCheck(string $corpusPath): array {
    $bin = __DIR__ . '/../../bin/refs-check';
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($bin) . ' ' . escapeshellarg($corpusPath) . ' 2>&1';
    exec($cmd, $out, $code);
    return ['code' => $code, 'output' => implode("\n", $out)];
}

function writeRefsCheckFixture(string $contents): string {
    $path = sys_get_temp_dir() . '/saqr-refscheck-' . uniqid() . '.json';
    file_put_contents($path, $contents);
    return $path;
}

test('refs-check fails on a missing corpus file', function () {
    $r = runRefsCheck(sys_get_temp_dir() . '/saqr-refscheck-absent-' . uniqid() . '.json');
    expect($r['code'])->toBe(1)->and($r['output'])->toContain('not found or unreadable');
});

test('refs-check fails on unparseable corpus json', function () {
    $path = writeRefsCheckFixture('{ this is not json ');
    $r = runRefsCheck($path);
    expect($r['code'])->toBe(1)->and($r['output'])->toContain('no entries to check');
    unlink($path);
});

test('refs-check fails on a corpus with an empty entries array', function () {
    $path = writeRefsCheckFixture('{"schema_version":"0.2","entries":[]}');
    $r = runRefsCheck($path);
    expect($r['code'])->toBe(1)->and($r['output'])->toContain('no entries to check');
    unlink($path);
});

test('refs-check fails when entries exist but no ref url was checked', function () {
    $path = writeRefsCheckFixture('{"entries":[{"id":"meta-only"}]}');
    $r = runRefsCheck($path);
    expect($r['code'])->toBe(1)->and($r['output'])->toContain('no ref urls were checked');
    unlink($path);
});
