<?php
declare(strict_types=1);

namespace Saqr;

use Saqr\Exception\InvalidCorpusException;

/**
 * Loads a Saqr corpus from a JSON file on disk.
 *
 * Expected shape:
 * {
 *   "schema_version": "0.1",
 *   "language": "en",
 *   "entries": [
 *     { "category": "...", "keywords": ["..."], "answer": "..." },
 *     ...
 *   ]
 * }
 */
final class Corpus
{
    /** @var array<int, array{category: ?string, keywords: array<int, string>, answer: string}> */
    private array $entries;

    /**
     * @param array<int, array{category?: ?string, keywords: array<int, string>, answer: string}> $entries
     */
    public function __construct(array $entries)
    {
        $this->entries = $entries;
    }

    public static function loadFromFile(string $path): self
    {
        if (!is_readable($path)) {
            throw new InvalidCorpusException("Corpus file not readable: {$path}");
        }
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new InvalidCorpusException("Failed to read corpus file: {$path}");
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new InvalidCorpusException("Corpus JSON is not a valid object");
        }
        if (!isset($decoded['entries']) || !is_array($decoded['entries'])) {
            throw new InvalidCorpusException("Corpus must contain an 'entries' array");
        }

        $clean = [];
        foreach ($decoded['entries'] as $i => $entry) {
            if (!is_array($entry)) {
                throw new InvalidCorpusException("Entry #{$i} is not an object");
            }
            if (!isset($entry['keywords']) || !is_array($entry['keywords'])) {
                throw new InvalidCorpusException("Entry #{$i} missing 'keywords' array");
            }
            if (!isset($entry['answer']) || !is_string($entry['answer'])) {
                throw new InvalidCorpusException("Entry #{$i} missing 'answer' string");
            }
            $clean[] = [
                'category' => isset($entry['category']) && is_string($entry['category']) ? $entry['category'] : null,
                'keywords' => array_values(array_filter($entry['keywords'], 'is_string')),
                'answer'   => $entry['answer'],
            ];
        }

        return new self($clean);
    }

    /**
     * @return array<int, array{category: ?string, keywords: array<int, string>, answer: string}>
     */
    public function all(): array
    {
        return $this->entries;
    }

    public function count(): int
    {
        return count($this->entries);
    }
}
