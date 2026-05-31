<?php
declare(strict_types=1);

/**
 * Runs the corpus-lint binary against the production corpus and asserts a
 * clean pass, plus drives it against crafted bad corpora (written to a temp
 * file) to prove each rule actually catches its violation.
 */

function runCorpusLint(string $corpusPath): array {
    $bin = __DIR__ . '/../../bin/corpus-lint';
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($bin) . ' ' . escapeshellarg($corpusPath) . ' 2>&1';
    exec($cmd, $out, $code);
    return ['code' => $code, 'output' => implode("\n", $out)];
}

function writeTempCorpus(array $entries): string {
    $path = sys_get_temp_dir() . '/saqr-corpus-' . uniqid() . '.json';
    file_put_contents($path, json_encode(['schema_version' => '0.2', 'entries' => $entries]));
    return $path;
}

test('production corpus passes corpus-lint', function () {
    $r = runCorpusLint(__DIR__ . '/../../corpus/frameworks.json');
    expect($r['code'])->toBe(0, $r['output']);
});

test('lint fails when an entry is missing an id', function () {
    $path = writeTempCorpus([
        ['category' => 'META', 'keywords' => ['x'], 'answer' => 'ok'],
    ]);
    $r = runCorpusLint($path);
    expect($r['code'])->toBe(1)->and($r['output'])->toContain('missing id');
    unlink($path);
});

test('lint fails on duplicate ids', function () {
    $path = writeTempCorpus([
        ['id' => 'dup', 'category' => 'META', 'keywords' => ['x'], 'answer' => 'ok'],
        ['id' => 'dup', 'category' => 'META', 'keywords' => ['y'], 'answer' => 'ok'],
    ]);
    $r = runCorpusLint($path);
    expect($r['code'])->toBe(1)->and($r['output'])->toContain('duplicate id');
    unlink($path);
});

test('lint fails on em-dash in answer', function () {
    $path = writeTempCorpus([
        ['id' => 'emdash', 'category' => 'META', 'keywords' => ['x'], 'answer' => 'A robust thing — and more.'],
    ]);
    $r = runCorpusLint($path);
    expect($r['code'])->toBe(1)->and($r['output'])->toContain('em-dash');
    unlink($path);
});

test('lint fails on a banned puff word', function () {
    $path = writeTempCorpus([
        ['id' => 'puff', 'category' => 'META', 'keywords' => ['x'], 'answer' => 'A comprehensive overview.'],
    ]);
    $r = runCorpusLint($path);
    expect($r['code'])->toBe(1)->and($r['output'])->toContain('comprehensive');
    unlink($path);
});

test('lint fails on a missing corpus file', function () {
    $r = runCorpusLint(sys_get_temp_dir() . '/saqr-does-not-exist-' . uniqid() . '.json');
    expect($r['code'])->toBe(1)->and($r['output'])->toContain('corpus');
});

test('lint fails on unparseable corpus json', function () {
    $path = sys_get_temp_dir() . '/saqr-bad-' . uniqid() . '.json';
    file_put_contents($path, '{ this is not json ');
    $r = runCorpusLint($path);
    expect($r['code'])->toBe(1)->and($r['output'])->toContain('corpus');
    unlink($path);
});
