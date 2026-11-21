<?php

namespace Tests\Unit;

use App\Services\Search\RankingLabMetrics;
use Tests\TestCase;

class RankingLabMetricsTest extends TestCase
{
    public function test_toy_three_class_matrix_matches_hand_computation(): void
    {
        $labels = ['A', 'B', 'C'];
        $yTrue = ['A', 'A', 'B', 'B', 'C'];
        $yPred = ['A', 'B', 'B', 'B', 'C'];

        $grid = RankingLabMetrics::confusionMatrix($yTrue, $yPred, $labels);

        $this->assertSame(1, $grid['A']['A']);
        $this->assertSame(1, $grid['A']['B']);
        $this->assertSame(0, $grid['A']['C']);
        $this->assertSame(2, $grid['B']['B']);
        $this->assertSame(1, $grid['C']['C']);

        $this->assertEqualsWithDelta(0.8, RankingLabMetrics::accuracy($yTrue, $yPred), 1e-12);

        $scores = RankingLabMetrics::familyScores($grid, $labels);
        $this->assertEqualsWithDelta(1.0, $scores['per_class']['A']['precision'], 1e-12);
        $this->assertEqualsWithDelta(0.5, $scores['per_class']['A']['recall'], 1e-12);
        $this->assertEqualsWithDelta(2 / 3, $scores['per_class']['A']['f1'], 1e-12);
        $this->assertEqualsWithDelta(2 / 3, $scores['per_class']['B']['precision'], 1e-12);
        $this->assertEqualsWithDelta(1.0, $scores['per_class']['B']['recall'], 1e-12);
        $this->assertEqualsWithDelta(0.8, $scores['per_class']['B']['f1'], 1e-12);
        $this->assertEqualsWithDelta(1.0, $scores['per_class']['C']['f1'], 1e-12);

        $expectedMacroF1 = ((2 / 3) + 0.8 + 1.0) / 3;
        $this->assertEqualsWithDelta((0.5 + 1.0 + 1.0) / 3, $scores['macro_recall'], 1e-12);
        $this->assertEqualsWithDelta($expectedMacroF1, $scores['macro_f1'], 1e-12);

        $expectedMcc = 11 / sqrt(14 * 16);
        $this->assertEqualsWithDelta($expectedMcc, RankingLabMetrics::mcc($yTrue, $yPred), 1e-12);
    }

    public function test_f1_is_zero_when_class_has_no_true_positives(): void
    {
        $labels = ['A', 'B'];
        $grid = [
            'A' => ['A' => 0, 'B' => 2],
            'B' => ['A' => 3, 'B' => 0],
        ];

        $scores = RankingLabMetrics::familyScores($grid, $labels);

        $this->assertSame(0.0, $scores['per_class']['A']['f1']);
        $this->assertSame(0.0, $scores['per_class']['B']['f1']);
        $this->assertSame(0.0, $scores['macro_f1']);
    }

    public function test_never_predicted_class_has_precision_zero_and_undefined_flag(): void
    {
        $labels = ['A', 'B'];
        $yTrue = ['A', 'A', 'B'];
        $yPred = ['A', 'A', 'A'];
        $grid = RankingLabMetrics::confusionMatrix($yTrue, $yPred, $labels);
        $scores = RankingLabMetrics::familyScores($grid, $labels);

        $this->assertSame(0.0, $scores['per_class']['B']['precision']);
        $this->assertTrue($scores['per_class']['B']['precision_undefined']);
        $this->assertFalse($scores['per_class']['A']['precision_undefined']);
    }

    public function test_kappa_is_one_when_graders_agree(): void
    {
        $a = ['Drinking / cow', 'Condensed', 'Infant'];
        $b = ['Drinking / cow', 'Condensed', 'Infant'];

        $this->assertEqualsWithDelta(1.0, RankingLabMetrics::cohenKappa($a, $b), 1e-12);
    }
}
