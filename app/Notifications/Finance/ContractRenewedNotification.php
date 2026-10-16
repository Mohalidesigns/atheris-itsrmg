<?php

namespace App\Notifications\Finance;

use App\Notifications\BaseNotification;

/** A contract was renewed, replaced or auto-renewed (ATH-FIN-001 §7). Carries no amounts. */
class ContractRenewedNotification extends BaseNotification
{
    protected string $type = 'finance.contract_renewed';

    protected string $module = 'IT Financial Management';

    /** @param string $outcome 'renewed', 'replaced' or 'auto_renewed' */
    public function __construct(public string $contractLabel, public ?string $successorLabel, public string $outcome, public ?string $newEndsOn, string $url)
    {
        $this->actionUrl = $url;
    }

    private function verb(): string
    {
        return ['auto_renewed' => 'auto-renewed', 'replaced' => 'replaced'][$this->outcome] ?? 'renewed';
    }

    protected function getSubject(): string
    {
        return "{$this->contractLabel} {$this->verb()}";
    }

    protected function getMessage(): string
    {
        return $this->successorLabel
            ? "Next term {$this->successorLabel}".($this->newEndsOn ? " runs to {$this->newEndsOn}" : ' has been created').'.'
            : "{$this->contractLabel} was {$this->verb()}.";
    }
}
