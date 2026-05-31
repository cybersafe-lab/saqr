<?php
declare(strict_types=1);

namespace Saqr\Eval;

/**
 * Pure retrieval-quality metrics over ranked id lists. No I/O.
 * @param list<string> $ranked   retrieved ids, best first
 * @param list<string> $expected gold ids for the query
 */
final class Metrics
{
    public function precisionAtK(array $ranked, array $expected, int $k): float
    {
        $top = array_slice($ranked, 0, $k);
        foreach ($top as $id) {
            if (in_array($id, $expected, true)) {
                return 1.0;
            }
        }
        return 0.0;
    }

    public function recallAtK(array $ranked, array $expected, int $k): float
    {
        if ($expected === []) {
            return 0.0;
        }
        $top = array_slice($ranked, 0, $k);
        $hits = 0;
        foreach ($expected as $id) {
            if (in_array($id, $top, true)) {
                $hits++;
            }
        }
        return $hits / count($expected);
    }

    public function reciprocalRank(array $ranked, array $expected): float
    {
        foreach ($ranked as $i => $id) {
            if (in_array($id, $expected, true)) {
                return 1.0 / ($i + 1);
            }
        }
        return 0.0;
    }
}
