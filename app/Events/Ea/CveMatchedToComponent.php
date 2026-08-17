<?php

namespace App\Events\Ea;

use App\Models\Ea\TechComponent;
use App\Models\Ea\TechVulnerability;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * §7.5: Ea\Events\CveMatchedToComponent → SecOps\Listeners\OpenVulnerability
 *
 * Contract I-2. Fired by SyncCveFeed when a CVE matches a deployed component.
 */
class CveMatchedToComponent
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public TechComponent $component,
        public TechVulnerability $vulnerability,
    ) {
    }
}
