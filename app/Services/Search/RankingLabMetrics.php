<?php

namespace App\Services\Search;

class RankingLabMetrics
{
    /**
     * @param  array<int, string>  $yTrue
     * @param  array<int, string>  $yPred
     * @param  array<int, string>  $labels
     * @return array<string, array<string, int>>
     */
    public static function confusionMatrix(array $yTrue, array $yPred, array $labels): array
    {
        $grid = [];
        foreach ($labels as $gold) {
            foreach ($labels as $pred) {
                $grid[$gold][$pred] = 0;
            }
        }

        foreach ($yTrue as $i => $gold) {
            $pred = $yPred[$i] ?? 'Unmapped';
            if (! isset($grid[$gold])) {
                $gold = 'Other';
                if (! isset($grid[$gold])) {
                    continue;
                }
            }
            if (! isset($grid[$gold][$pred])) {
                $pred = 'Unmapped';
                if (! isset($grid[$gold][$pred])) {
                    $pred = array_key_first($grid[$gold]) ?? $gold;
                }
            }
            $grid[$gold][$pred]++;
        }

        return $grid;
    }

    /**
     * @param  array<int, string>  $yTrue
     * @param  array<int, string>  $yPred
     */
    public static function accuracy(array $yTrue, array $yPred): float
    {
        $n = count($yTrue);
        if ($n === 0) {
            return 0.0;
        }

        $hits = 0;
        foreach ($yTrue as $i => $gold) {
            if (($yPred[$i] ?? null) === $gold) {
                $hits++;
            }
        }

        return $hits / $n;
    }

    /**
     * sklearn.metrics.precision/recall/f1 with zero_division=0, then unweighted
     * mean over gold labels that have support > 0.
     *
     * @param  array<string, array<string, int>>  $grid
     * @param  array<int, string>  $labels
     * @return array{per_class: array<string, array<string, mixed>>, macro_precision: float, macro_recall: float, macro_f1: float, weighted_f1: float, k: int}
     */
    public static function familyScores(array $grid, array $labels): array
    {
        $rowTotals = [];
        $colTotals = [];
        $n = 0;
        foreach ($labels as $gold) {
            $rowTotals[$gold] = 0;
            $colTotals[$gold] = 0;
        }
        foreach ($labels as $gold) {
            foreach ($labels as $pred) {
                $count = $grid[$gold][$pred] ?? 0;
                $rowTotals[$gold] += $count;
                $colTotals[$pred] += $count;
                $n += $count;
            }
        }

        $perClass = [];
        $macroP = 0.0;
        $macroR = 0.0;
        $macroF = 0.0;
        $weightedF = 0.0;
        $k = 0;

        foreach ($labels as $label) {
            $tp = (float) ($grid[$label][$label] ?? 0);
            $support = (float) $rowTotals[$label];
            $predicted = (float) $colTotals[$label];

            $precisionUndefined = $predicted == 0.0;
            $recallUndefined = $support == 0.0;
            $precision = $precisionUndefined ? 0.0 : $tp / $predicted;
            $recall = $recallUndefined ? 0.0 : $tp / $support;
            $f1Denom = $precision + $recall;
            $f1 = $f1Denom == 0.0 ? 0.0 : (2.0 * $precision * $recall) / $f1Denom;

            $perClass[$label] = [
                'support' => $support,
                'precision' => $precision,
                'recall' => $recall,
                'f1' => $f1,
                'precision_undefined' => $precisionUndefined,
                'recall_undefined' => $recallUndefined,
            ];

            if ($support > 0.0) {
                $k++;
                $macroP += $precision;
                $macroR += $recall;
                $macroF += $f1;
                $weightedF += $f1 * $support;
            }
        }

        return [
            'per_class' => $perClass,
            'macro_precision' => $k === 0 ? 0.0 : $macroP / $k,
            'macro_recall' => $k === 0 ? 0.0 : $macroR / $k,
            'macro_f1' => $k === 0 ? 0.0 : $macroF / $k,
            'weighted_f1' => $n === 0 ? 0.0 : $weightedF / $n,
            'k' => $k,
            'n' => $n,
        ];
    }

    /**
     * Multiclass MCC (sklearn.metrics.matthews_corrcoef).
     *
     * @param  array<int, string>  $yTrue
     * @param  array<int, string>  $yPred
     */
    public static function mcc(array $yTrue, array $yPred): float
    {
        $n = count($yTrue);
        if ($n === 0) {
            return 0.0;
        }

        $labels = array_values(array_unique(array_merge($yTrue, $yPred)));
        $t = [];
        $p = [];
        foreach ($labels as $label) {
            $t[$label] = 0;
            $p[$label] = 0;
        }

        $c = 0;
        foreach ($yTrue as $i => $gold) {
            $pred = $yPred[$i] ?? '';
            $t[$gold] = ($t[$gold] ?? 0) + 1;
            $p[$pred] = ($p[$pred] ?? 0) + 1;
            if ($pred === $gold) {
                $c++;
            }
        }

        $sumTp = 0.0;
        $sumP2 = 0.0;
        $sumT2 = 0.0;
        foreach ($labels as $label) {
            $sumTp += $t[$label] * $p[$label];
            $sumP2 += $p[$label] * $p[$label];
            $sumT2 += $t[$label] * $t[$label];
        }

        $s = (float) $n;
        $numerator = ($c * $s) - $sumTp;
        $denominator = sqrt(($s * $s - $sumP2) * ($s * $s - $sumT2));
        if ($denominator == 0.0) {
            return 0.0;
        }

        return $numerator / $denominator;
    }

    /**
     * @param  array<int, string>  $a
     * @param  array<int, string>  $b
     */
    public static function cohenKappa(array $a, array $b): float
    {
        $n = count($a);
        if ($n === 0 || $n !== count($b)) {
            return 0.0;
        }

        $po = self::accuracy($a, $b);
        $labels = array_values(array_unique(array_merge($a, $b)));
        $countA = array_fill_keys($labels, 0);
        $countB = array_fill_keys($labels, 0);
        foreach ($a as $i => $label) {
            $countA[$label]++;
            $countB[$b[$i]]++;
        }

        $pe = 0.0;
        foreach ($labels as $label) {
            $pe += ($countA[$label] / $n) * ($countB[$label] / $n);
        }

        if ($pe >= 1.0) {
            return 1.0;
        }

        return ($po - $pe) / (1.0 - $pe);
    }
}
