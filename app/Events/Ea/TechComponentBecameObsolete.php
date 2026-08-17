<?php

namespace App\Events\Ea;

use App\Models\Ea\TechComponent;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * §7.5: Ea\Events\TechComponentBecameObsolete → RiskModule\Listeners\OpenObsolescenceRisk
 *
 * Fired by RecomputeTechObsolescence when a component crosses the EOL/tech-debt
 * threshold with no replacement initiative in flight. Contract I-1.
 */
class TechComponentBecameObsolete
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public TechComponent $component,
        public array $context = [],
    ) {
    }
}
