<?php
declare(strict_types=1);

namespace Saqr\RateLimiter;

/**
 * In-process rate limiter. Counters live in a static array, so they reset
 * whenever the PHP process restarts. Useful for CLI demos, tests, and
 * single-worker setups; substitute a Redis-backed implementation for any
 * multi-process or multi-host deployment.
 */
final class InMemoryRateLimiter implements RateLimiterInterface
{
    /** @var array<string, array{count: int, expires: int}> */
    private array $buckets = [];

    public function tryAcquire(string $key, int $cap, int $windowSeconds): bool
    {
        $now = time();

        if (!isset($this->buckets[$key]) || $this->buckets[$key]['expires'] <= $now) {
            $this->buckets[$key] = ['count' => 0, 'expires' => $now + max(1, $windowSeconds)];
        }

        if ($this->buckets[$key]['count'] >= $cap) {
            return false;
        }

        $this->buckets[$key]['count']++;
        return true;
    }
}
