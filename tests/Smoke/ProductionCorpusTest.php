<?php
declare(strict_types=1);

use Saqr\Corpus;

test('production corpus loads and has expected shape', function () {
    $c = Corpus::loadFromFile(__DIR__ . '/../../corpus/frameworks.json');
    expect(count($c->all()))->toBeGreaterThanOrEqual(25)->toBeLessThanOrEqual(50);
    foreach ($c->all() as $entry) {
        expect($entry)->toHaveKeys(['id', 'keywords', 'answer']);
        expect($entry['keywords'])->toBeArray();
    }
});
