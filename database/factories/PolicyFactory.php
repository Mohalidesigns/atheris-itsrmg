<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\Policy;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class PolicyFactory extends Factory
{
    protected $model = Policy::class;

    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'policy_code' => 'POL-'.strtoupper($this->faker->bothify('###')),
            'title' => $this->faker->sentence(4),
            'description' => $this->faker->sentence(),
            'content' => "# ".$this->faker->sentence()."\n\n".$this->faker->paragraphs(3, true),
            'category' => $this->faker->randomElement(['security', 'privacy', 'risk', 'governance']),
            'status' => $this->faker->randomElement(['active', 'draft', 'under_review', 'approved', 'expired', 'archived']),
            'version_number' => '1.0',
            'owner_id' => User::factory(),
            'approved_by' => User::factory(),
            'approved_at' => $this->faker->dateTimeBetween('-1 year', 'now'),
            'published_at' => $this->faker->dateTimeBetween('-1 year', 'now'),
            'effective_date' => $this->faker->dateTimeBetween('-6 months', 'now')->format('Y-m-d'),
            'review_date' => $this->faker->dateTimeBetween('+6 months', '+1 year')->format('Y-m-d'),
            'expiry_date' => $this->faker->dateTimeBetween('+1 year', '+2 years')->format('Y-m-d'),
            'is_mandatory' => true,
        ];
    }
}
