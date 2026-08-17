<?php

namespace App\Notifications\Ea;

use App\Models\Ea\QualitySeal;
use App\Notifications\BaseNotification;
use App\Services\Ea\EntityRegistry;

/**
 * Warns the Responsible and Accountable owners before a seal expires on
 * schedule, so re-validation is a task rather than a surprise.
 *
 * This one *is* a BaseNotification: seal approvers are always real users, since
 * approval requires a subscription.
 */
class QualitySealExpiringNotification extends BaseNotification
{
    protected string $type = 'ea.seal.expiring';
    protected string $module = 'ea';

    public function __construct(public QualitySeal $seal, public int $daysRemaining)
    {
        $definition = EntityRegistry::definition($seal->entity_type);
        $this->actionUrl = ($definition['route'] ?? null)
            ? route($definition['route'])
            : route('ea.stewardship.my-architecture');
    }

    protected function getSubject(): string
    {
        return 'Architecture record needs re-validation in '.$this->daysRemaining.' day(s)';
    }

    protected function getMessage(): string
    {
        $label = EntityRegistry::describe($this->seal->entity_type, $this->seal->entity_id);

        return "The quality seal on {$label} expires in {$this->daysRemaining} day(s). "
            .'Confirm the record is still accurate to keep it approved — approved data is what the '
            .'CBN returns cite as evidence.';
    }
}
