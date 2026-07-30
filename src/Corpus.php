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
 *     { "id": "...", "title": "...", "framework": "...",
 *       "category": "...", "keywords": ["..."], "answer": "...",
 *       "refs": [{ "title": "...", "url": "..." }] },
 *     ...
 *   ]
 * }
 *
 * Required per-entry fields: keywords + answer.
 * Optional (preserved when present): id, title, framework, category, refs.
 * A "refs" entry that is not an object carrying string title + url is
 * dropped; entries without "refs" normalize to an empty array, so every
 * consumer can read $entry['refs'] unconditionally.
 * Consumers that surface entries to downstream clients (e.g. the MCP
 * tool result mapper in bin/dispatcher.php) rely on these fields being
 * preserved verbatim when supplied.
 */
final class Corpus
{
    /** @var array<int, array{id: ?string, title: ?string, framework: ?string, category: ?string, keywords: array<int, string>, answer: string, refs: array<int, array{title: string, url: string}>}> */
    private array $entries;

    /**
     * @param array<int, array{id?: ?string, title?: ?string, framework?: ?string, category?: ?string, keywords: array<int, string>, answer: string, refs?: array<int, array{title: string, url: string}>}> $entries
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
                'id'        => isset($entry['id']) && is_string($entry['id']) ? $entry['id'] : null,
                'title'     => isset($entry['title']) && is_string($entry['title']) ? $entry['title'] : null,
                'framework' => isset($entry['framework']) && is_string($entry['framework']) ? $entry['framework'] : null,
                'category'  => isset($entry['category']) && is_string($entry['category']) ? $entry['category'] : null,
                'refs'      => isset($entry['refs']) && is_array($entry['refs'])
                    ? array_values(array_filter(array_map(
                        static fn ($r) => is_array($r)
                            && isset($r['title'], $r['url'])
                            && is_string($r['title']) && is_string($r['url'])
                            ? ['title' => $r['title'], 'url' => $r['url']]
                            : null,
                        $entry['refs']
                    )))
                    : [],
                'keywords'  => array_values(array_filter($entry['keywords'], 'is_string')),
                'answer'    => $entry['answer'],
            ];
        }

        return new self($clean);
    }

    /**
     * @return array<int, array{id: ?string, title: ?string, framework: ?string, category: ?string, keywords: array<int, string>, answer: string, refs: array<int, array{title: string, url: string}>}>
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
