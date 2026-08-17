<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\Vendor;
use Illuminate\Database\Eloquent\Factories\Factory;

class VendorFactory extends Factory
{
    protected $model = Vendor::class;

    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'vendor_code' => 'VEN-'.strtoupper($this->faker->bothify('###')),
            'name' => $this->faker->company(),
            'description' => $this->faker->sentence(),
            'category' => $this->faker->randomElement(['Payments', 'Cloud', 'Telecoms', 'Auditing', 'Security', 'Core Banking']),
            'risk_level' => $this->faker->randomElement(['critical', 'high', 'medium', 'low']),
            'status' => $this->faker->randomElement(['active', 'inactive', 'under_review', 'terminated']),
            'contact_name' => $this->faker->name(),
            'contact_email' => $this->faker->safeEmail(),
            'contact_phone' => $this->faker->phoneNumber(),
            'website' => $this->faker->url(),
            'country' => 'NG',
            'contract_start' => $this->faker->dateTimeBetween('-5 years', '-1 year'),
            'contract_end' => $this->faker->dateTimeBetween('+1 year', '+3 years'),
            'contract_value' => $this->faker->numberBetween(10_000_000, 500_000_000),
            'contract_currency' => 'NGN',
            'data_access_level' => $this->faker->randomElement(['none', 'limited', 'full']),
            'last_assessed' => $this->faker->dateTimeBetween('-1 year', 'now'),
            'next_review_date' => $this->faker->dateTimeBetween('now', '+1 year'),
        ];
    }
}
