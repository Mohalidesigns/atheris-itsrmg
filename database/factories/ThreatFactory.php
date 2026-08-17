<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\Threat;
use Illuminate\Database\Eloquent\Factories\Factory;

class ThreatFactory extends Factory
{
    protected $model = Threat::class;

    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'threat_id_code' => 'THR-'.strtoupper($this->faker->bothify('###')),
            'name' => $this->faker->sentence(4),
            'description' => $this->faker->sentence(),
            'category' => $this->faker->randomElement(['fraud', 'phishing', 'credential', 'malware', 'network', 'insider', 'physical', 'supply-chain', 'ai-threat']),
            'type' => $this->faker->randomElement(['deliberate', 'accidental', 'environmental']),
            'severity' => $this->faker->randomElement(['critical', 'high', 'medium', 'low']),
            'likelihood' => $this->faker->numberBetween(1, 5),
            'capability' => $this->faker->numberBetween(1, 5),
            'intent' => $this->faker->numberBetween(1, 5),
            'is_active' => true,
            'source' => $this->faker->randomElement(['internal', 'ngcert', 'nitda', 'mitre-attck', 'ibm-x-force']),
            'tags' => [$this->faker->word()],
            'last_seen' => $this->faker->dateTimeBetween('-90 days', 'now'),
        ];
    }
}
