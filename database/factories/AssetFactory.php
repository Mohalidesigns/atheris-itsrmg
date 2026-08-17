<?php

namespace Database\Factories;

use App\Models\Asset;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class AssetFactory extends Factory
{
    protected $model = Asset::class;

    public function definition(): array
    {
        $type = $this->faker->randomElement(['hardware', 'software', 'cloud_service', 'database', 'network', 'facility']);
        return [
            'organization_id' => Organization::factory(),
            'asset_id_code' => 'AST-'.strtoupper($this->faker->bothify('###-??')),
            'name' => $this->faker->words(3, true),
            'description' => $this->faker->sentence(),
            'asset_type' => $type,
            'category' => $this->faker->randomElement(['Core Banking', 'Channel', 'Infrastructure', 'Network', 'Data', 'Server', 'Cloud', 'Security', 'Third-Party']),
            'criticality' => $this->faker->randomElement(['critical', 'high', 'medium', 'low']),
            'status' => $this->faker->randomElement(['active', 'inactive', 'decommissioned', 'under_review']),
            'owner_id' => User::factory(),
            'department' => $this->faker->randomElement(['IT', 'Security', 'Channels', 'Payments', 'Treasury']),
            'location' => $this->faker->randomElement(['Kano HQ', 'Lagos DR', 'AWS af-south-1']),
            'ip_address' => $this->faker->ipv4(),
            'hostname' => $this->faker->domainWord(),
            'vendor' => $this->faker->company(),
            'data_classification' => $this->faker->randomElement(['public', 'internal', 'confidential', 'restricted']),
            'purchase_date' => $this->faker->dateTimeBetween('-6 years', '-1 year')->format('Y-m-d'),
            'end_of_life' => $this->faker->dateTimeBetween('+1 year', '+7 years')->format('Y-m-d'),
            'tags' => [$type],
        ];
    }
}
