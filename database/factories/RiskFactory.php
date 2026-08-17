<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\Risk;
use App\Models\User;
use App\Models\RiskCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

class RiskFactory extends Factory
{
    protected $model = Risk::class;

    public function definition(): array
    {
        $likelihood = $this->faker->numberBetween(1, 5);
        $impact = $this->faker->numberBetween(1, 5);
        $score = $likelihood * $impact;
        $rating = $score >= 20 ? 'critical' : ($score >= 12 ? 'high' : ($score >= 6 ? 'medium' : 'low'));
        $residualL = max(1, $likelihood - $this->faker->numberBetween(0, 2));
        $residualI = max(1, $impact - $this->faker->numberBetween(0, 2));
        $residualScore = $residualL * $residualI;
        $residualRating = $residualScore >= 20 ? 'critical' : ($residualScore >= 12 ? 'high' : ($residualScore >= 6 ? 'medium' : 'low'));

        return [
            'organization_id' => Organization::factory(),
            'risk_id_code' => 'RSK-'.strtoupper($this->faker->bothify('###-??')),
            'title' => $this->faker->sentence(6),
            'description' => $this->faker->paragraph(3),
            'category_id' => RiskCategory::factory(),
            'risk_owner_id' => User::factory(),
            'created_by' => User::factory(),
            'status' => $this->faker->randomElement(['identified', 'assessed', 'mitigated', 'accepted', 'in_progress', 'under_review', 'closed']),
            'inherent_likelihood' => $likelihood,
            'inherent_impact' => $impact,
            'inherent_score' => $score,
            'inherent_rating' => $rating,
            'residual_likelihood' => $residualL,
            'residual_impact' => $residualI,
            'residual_score' => $residualScore,
            'residual_rating' => $residualRating,
            'fair_annual_loss_expectancy' => $this->faker->numberBetween(5_000_000, 500_000_000),
            'fair_single_loss_expectancy' => $this->faker->numberBetween(2_000_000, 100_000_000),
            'treatment_strategy' => $this->faker->randomElement(['mitigate', 'accept', 'transfer', 'avoid']),
            'treatment_due_date' => $this->faker->dateTimeBetween('-30 days', '+180 days'),
            'risk_appetite' => $this->faker->randomElement(['within', 'above', 'below']),
            'source' => $this->faker->randomElement(['self-assessment', 'audit', 'incident', 'regulator']),
            'review_date' => $this->faker->dateTimeBetween('+1 months', '+12 months'),
        ];
    }

    public function critical(): self
    {
        return $this->state(fn () => [
            'inherent_likelihood' => 5, 'inherent_impact' => 5, 'inherent_score' => 25, 'inherent_rating' => 'critical',
        ]);
    }
}
