<?php

namespace Database\Factories;

use App\Models\Control;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class ControlFactory extends Factory
{
    protected $model = Control::class;

    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'control_code' => 'CTL-'.strtoupper($this->faker->bothify('###-??')),
            'title' => $this->faker->sentence(5),
            'description' => $this->faker->paragraph(2),
            'domain' => $this->faker->randomElement(['IAM', 'Network', 'Data Protection', 'Governance', 'Cryptography', 'Monitoring']),
            'category' => $this->faker->randomElement(['IAM', 'Network', 'Data Protection']),
            'type' => $this->faker->randomElement(['preventive', 'detective', 'corrective', 'deterrent']),
            'nature' => $this->faker->randomElement(['technical', 'administrative', 'physical']),
            'frequency' => $this->faker->randomElement(['continuous', 'daily', 'weekly', 'monthly', 'quarterly', 'annual']),
            'owner_id' => User::factory(),
            'status' => $this->faker->randomElement(['active', 'draft', 'under_review', 'deprecated']),
            'effectiveness' => $this->faker->randomElement(['effective', 'partially_effective', 'ineffective', 'not_assessed']),
            'is_key_control' => $this->faker->boolean(30),
            'last_tested' => $this->faker->dateTimeBetween('-1 year', 'now'),
            'next_review_date' => $this->faker->dateTimeBetween('now', '+1 year'),
            'sort_order' => $this->faker->numberBetween(0, 500),
        ];
    }
}
