<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\RiskCategory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class RiskCategoryFactory extends Factory
{
    protected $model = RiskCategory::class;

    public function definition(): array
    {
        $name = $this->faker->randomElement(['Cyber', 'Technology', 'Operational Technology', 'Third-Party', 'Data Protection', 'Fraud', 'Physical', 'Regulatory']);
        return [
            'organization_id' => Organization::factory(),
            'name' => $name,
            'slug' => Str::slug($name),
            'description' => $name.' risk category.',
        ];
    }
}
