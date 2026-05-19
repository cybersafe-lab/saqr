<?php
declare(strict_types=1);

namespace Saqr;

use Saqr\RateLimiter\InMemoryRateLimiter;
use Saqr\RateLimiter\RateLimiterInterface;

/**
 * Public-facing orchestrator. Composes Corpus + Retriever + (optional)
 * Generator + RateLimiter into a single ask() call that mirrors the
 * production handler.
 *
 * Typical usage:
 *
 *   $pipeline = new \Saqr\Pipeline(
 *       \Saqr\Corpus::loadFromFile(__DIR__ . '/corpus/frameworks.json')
 *   );
 *   $result = $pipeline->ask("What is NCA ECC?");
 *   echo $result['answer'];
 *
 * Rate-limiting is on by default with a per-client hourly cap of 20 and
 * a global daily cap of 2000 — matches the production deployment. Pass a
 * different RateLimiterInterface (Redis, Memcached, ...) for multi-process
 * deployments.
 */
final class Pipeline
{
    private Corpus $corpus;
    private Retriever $retriever;
    private Generator $generator;
    private RateLimiterInterface $rateLimiter;
    private int $perClientHourlyCap;
    private int $globalDailyCap;

    public function __construct(
        Corpus $corpus,
        ?Generator $generator = null,
        ?RateLimiterInterface $rateLimiter = null,
        int $perClientHourlyCap = 20,
        int $globalDailyCap = 2000
    ) {
        $this->corpus = $corpus;
        $this->retriever = new Retriever($corpus);
        $this->generator = $generator ?? new Generator();
        $this->rateLimiter = $rateLimiter ?? new InMemoryRateLimiter();
        $this->perClientHourlyCap = $perClientHourlyCap;
        $this->globalDailyCap = $globalDailyCap;
    }

    /**
     * Ask a question. Returns:
     *   [
     *     'ok'         => bool,
     *     'reason'     => ?string,  // 'rate_limited' | 'empty_question' | 'no_match'
     *     'answer'     => ?string,  // sanitized HTML
     *     'used_llm'   => bool,
     *     'top'        => array<int, KbEntry>,
     *   ]
     *
     * @return array{ok: bool, reason: ?string, answer: ?string, used_llm: bool, top: array<int, array{category: ?string, keywords: array<int, string>, answer: string}>}
     */
    public function ask(string $question, string $clientId = 'anonymous'): array
    {
        $question = trim($question);
        if ($question === '') {
            return ['ok' => false, 'reason' => 'empty_question', 'answer' => null, 'used_llm' => false, 'top' => []];
        }
        if (mb_strlen($question) > 500) {
            $question = mb_substr($question, 0, 500);
        }

        $today = gmdate('Y-m-d');
        if (!$this->rateLimiter->tryAcquire('global:' . $today, $this->globalDailyCap, 86400)) {
            return ['ok' => false, 'reason' => 'rate_limited', 'answer' => null, 'used_llm' => false, 'top' => []];
        }
        if (!$this->rateLimiter->tryAcquire('client:' . $clientId, $this->perClientHourlyCap, 3600)) {
            return ['ok' => false, 'reason' => 'rate_limited', 'answer' => null, 'used_llm' => false, 'top' => []];
        }

        $top = $this->retriever->retrieveTopK($question, 3);
        if (empty($top)) {
            return ['ok' => true, 'reason' => 'no_match', 'answer' => null, 'used_llm' => false, 'top' => []];
        }

        $llmAnswer = $this->generator->generate($question, $top);
        if ($llmAnswer !== null && $llmAnswer !== '') {
            return ['ok' => true, 'reason' => null, 'answer' => $llmAnswer, 'used_llm' => true, 'top' => $top];
        }

        // Fallback: return the top-ranked entry verbatim.
        return ['ok' => true, 'reason' => null, 'answer' => $top[0]['answer'], 'used_llm' => false, 'top' => $top];
    }
}
