<?php

namespace App\Events\Core;

use App\Models\Incident;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * IT-GOV-CAPA-001 §3.3: Core\Events\IncidentSaved → Core\Listeners\OpenCustomerImpactCapa.
 * Fires on every create and update; the listener acts only on customer-impacting incidents and is
 * idempotent per incident.
 */
class IncidentSaved
{
    use Dispatchable, SerializesModels;

    public function __construct(public Incident $incident) {}
}
