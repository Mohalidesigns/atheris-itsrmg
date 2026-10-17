<?php

namespace App\Notifications\Finance;

use App\Notifications\BaseNotification;

/** A vendor invoice is coming due or is overdue (ATH-FIN-001 §7). A negative $daysLeft means overdue. Carries no amounts. */
class InvoiceDueNotification extends BaseNotification
{
    protected string $type = 'finance.invoice_due';

    protected string $module = 'IT Financial Management';

    public function __construct(public string $invoiceCode, public string $vendor, public string $dueOn, public int $daysLeft, string $url)
    {
        $this->actionUrl = $url;
        if ($daysLeft < 0) {
            $this->type = 'finance.invoice_overdue';
        }
    }

    protected function getSubject(): string
    {
        return match (true) {
            $this->daysLeft < 0 => "{$this->invoiceCode}: invoice overdue by ".abs($this->daysLeft).' day(s)',
            $this->daysLeft === 0 => "{$this->invoiceCode}: invoice due today",
            default => "{$this->invoiceCode}: invoice due in {$this->daysLeft} day(s)",
        };
    }

    protected function getMessage(): string
    {
        return $this->daysLeft < 0
            ? "Invoice {$this->invoiceCode} from {$this->vendor} was due on {$this->dueOn} and is ".abs($this->daysLeft).' day(s) overdue.'
            : "Invoice {$this->invoiceCode} from {$this->vendor} is due on {$this->dueOn}".($this->daysLeft === 0 ? ' (today).' : " ({$this->daysLeft} day(s) left).");
    }
}
