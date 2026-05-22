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
