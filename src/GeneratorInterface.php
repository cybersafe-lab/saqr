<?php
declare(strict_types=1);

namespace Saqr;

/**
 * The answer-synthesis seam Pipeline depends on. Generator is the shipped
 * implementation (Anthropic Messages API); this interface is what lets a
 * deployment swap in another provider and what lets tests exercise the
 * synthesized-answer path without a key or a network call.
 *
 * Implementations must return HTML that is already safe to render — Pipeline
 * hands the string straight to its caller and does not sanitize it. Only
 * <strong>, <em> and <br> are expected downstream; Generator::sanitizeHtml is
 * available for reuse. Return null (or an empty string) to decline, which is
 * how "no key configured" and "the call failed" are reported: Pipeline then
 * falls back to the top retrieved entry verbatim. Never throw for an ordinary
 * failure — a declining generator must not take the request down with it.
 */
interface GeneratorInterface
{
    /**
     * @param array<int, array{category?: ?string, keywords: array<int, string>, answer: string}> $contextEntries
     *        The retrieved entries that ground the answer, best match first.
     * @return string|null Sanitized HTML, or null to decline.
     */
    public function generate(string $question, array $contextEntries): ?string;
}
