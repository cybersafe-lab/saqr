<?php
declare(strict_types=1);

use Saqr\Corpus;

test('loadFromFile reads the tiny fixture and exposes all entries', function () {
    $c = Corpus::loadFromFile(__DIR__ . '/../fixtures/corpus-tiny.json');
    expect($c->all())->toBeArray()->toHaveCount(6);
});

test('non-string keywords are silently filtered (defensive)', function () {
    $tmp = tempnam(sys_get_temp_dir(), 'corpus');
    file_put_contents($tmp, json_encode([
        'version' => 't',
        'entries' => [
            ['id' => 'x', 'keywords' => ['ok', 42, null, ['nested']], 'answer' => 'a'],
        ],
    ]));
    $c = Corpus::loadFromFile($tmp);
    unlink($tmp);
    $entry = $c->all()[0];
    expect($entry['keywords'])->toBe(['ok']);
});

// NOTE: Corpus does NOT check for duplicate IDs — entries with duplicate ids both load.
// This test characterizes that actual behavior rather than asserting a throw.
test('duplicate ids in corpus both load without error', function () {
    $tmp = tempnam(sys_get_temp_dir(), 'corpus');
    file_put_contents($tmp, json_encode([
        'version' => 't',
        'entries' => [
            ['id' => 'dup', 'keywords' => ['a'], 'answer' => 'one'],
            ['id' => 'dup', 'keywords' => ['b'], 'answer' => 'two'],
        ],
    ]));
    $c = Corpus::loadFromFile($tmp);
    unlink($tmp);
    expect($c->all())->toHaveCount(2);
    expect($c->all()[0]['id'])->toBe('dup');
    expect($c->all()[1]['id'])->toBe('dup');
});

test('loadFromFile preserves well-formed refs and defaults to empty array', function () {
    $path = tempnam(sys_get_temp_dir(), 'saqr');
    file_put_contents($path, json_encode(['entries' => [
        ['keywords' => ['a'], 'answer' => 'x',
         'refs' => [['title' => 'NCA ECC', 'url' => 'https://nca.gov.sa/x']]],
        ['keywords' => ['b'], 'answer' => 'y'],
    ]]));
    $entries = Corpus::loadFromFile($path)->all();
    unlink($path);
    expect($entries[0]['refs'])->toBe([['title' => 'NCA ECC', 'url' => 'https://nca.gov.sa/x']]);
    expect($entries[1]['refs'])->toBe([]);
});

test('loadFromFile drops malformed ref items', function () {
    $path = tempnam(sys_get_temp_dir(), 'saqr');
    file_put_contents($path, json_encode(['entries' => [
        ['keywords' => ['a'], 'answer' => 'x', 'refs' => [
            ['title' => 'ok', 'url' => 'https://example.gov.sa/d'],
            ['title' => 'no url'],
            'not-an-object',
        ]],
    ]]));
    $entries = Corpus::loadFromFile($path)->all();
    unlink($path);
    expect($entries[0]['refs'])->toBe([['title' => 'ok', 'url' => 'https://example.gov.sa/d']]);
});
