<?php

namespace Database\Factories;

use App\Models\Incident;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class IncidentFactory extends Factory
{
    protected $model = Incident::class;

    public function definition(): array
    {
        $detected = $this->faker->dateTimeBetween('-90 days', 'now');
        return [
            'organization_id' => Organization::factory(),
            'incident_id_code' => 'INC-'.strtoupper($this->faker->bothify('###')),
            'title' => $this->faker->sentence(6),
            'description' => $this->faker->paragraph(),
            'type' => $this->faker->randomElement(['malware', 'phishing', 'data_leak', 'unauthorized_access', 'dos', 'insider_threat', 'other']),
            'severity' => $this->faker->randomElement(['critical', 'high', 'medium', 'low']),
            'status' => $this->faker->randomElement(['detected', 'triaged', 'investigating', 'containing', 'eradicating', 'recovering', 'closed']),
            'source' => $this->faker->randomElement(['SIEM', 'SOC analyst', 'customer report', 'automated alert', 'ngCERT']),
            'detected_at' => $detected,
            'responded_at' => $detected,
            'assigned_to' => User::factory(),
            'lead_investigator_id' => User::factory(),
            'affected_systems' => [],
            'affected_users_count' => $this->faker->numberBetween(0, 500),
            'is_data_breach' => $this->faker->boolean(20),
            'root_cause' => $this->faker->sentence(),
        ];
    }

    public function dataBreach(): self
    {
        return $this->state(fn () => ['is_data_breach' => true, 'severity' => 'high']);
    }
}
