<?php

namespace Database\Factories;

use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class OrganizationFactory extends Factory
{
    protected $model = Organization::class;

    public function definition(): array
    {
        $name = $this->faker->company().' Bank';
        return [
            'name' => $name,
            'slug' => Str::slug($name),
            'industry' => 'Financial Services',
            'size' => $this->faker->randomElement(['small', 'medium', 'large', 'enterprise']),
            'country' => 'NG',
            'currency' => 'NGN',
            'subscription_plan' => $this->faker->randomElement(['essentials', 'professional', 'enterprise']),
            'subscription_expires_at' => now()->addYear(),
            'is_active' => true,
        ];
    }
}
