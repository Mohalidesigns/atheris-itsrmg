<?php

namespace App\Events\Core;

use App\Models\Incident;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * §7.5: Core\Events\IncidentDeclared → Ea\Listeners\AttachBlastRadius
 *
 * Contract I-13. The CBN framework gives a 30-minute response window; blast
 * radius being reachable only from inside the EA module does not help the
 * responder.
 */
class IncidentDeclared
{
    use Dispatchable, SerializesModels;

    public function __construct(public Incident $incident)
    {
    }
}
