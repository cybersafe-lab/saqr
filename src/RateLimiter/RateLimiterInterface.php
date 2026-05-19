<?php
declare(strict_types=1);

namespace Saqr\RateLimiter;

/**
 * Simple counter-with-window rate-limit contract.
 *
 * The Pipeline calls tryAcquire() before doing work; if the call returns
 * false, the Pipeline returns a "rate-limited" response without consulting
 * the retriever or the generator.
 *
 * Implementations can use any storage backend (in-memory, Redis,
 * Memcached, database) — the only requirements are atomicity of the
 * increment-and-check within a window, and TTL expiry of windows.
 */
interface RateLimiterInterface
{
    /**
     * Attempt to consume one slot under $key. Returns true if allowed,
     * false if the cap for the current window has been reached.
     *
     * @param string $key            Caller-defined identifier (e.g. "ip:1.2.3.4", "global:2026-05-19")
     * @param int    $cap            Max number of requests allowed within the window
     * @param int    $windowSeconds  Length of the window (e.g. 3600 for hourly)
     */
    public function tryAcquire(string $key, int $cap, int $windowSeconds): bool;
}
