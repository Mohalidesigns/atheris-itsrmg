<?php

namespace App\Notifications\Finance;

use App\Notifications\BaseNotification;

/** An accounting period is about to close, is overdue for close, or a close was refused (ATH-FIN-001 §7). Carries labels and counts only. */
class PeriodCloseReminderNotification extends BaseNotification
{
    public const KINDS = ['due', 'overdue', 'blocked'];

    protected string $type = 'finance.period_close_due';

    protected string $module = 'IT Financial Management';

    /** @param list<array{label:string,count?:int}> $openItems */
    public function __construct(public string $period, public string $kind, public int $workingDays, public array $openItems, string $url)
    {
        $this->actionUrl = $url;
        $this->type = 'finance.period_close_'.$kind;
    }

    protected function getSubject(): string
    {
        return match ($this->kind) {
            'overdue' => "{$this->period}: period close is overdue",
            'blocked' => "{$this->period}: period close was blocked",
            default => "{$this->period}: period closes in {$this->workingDays} working day(s)",
        };
    }

    protected function getMessage(): string
    {
        $open = collect($this->openItems)->map(fn ($i) => $i['label'].(isset($i['count']) && $i['count'] > 0 ? " ({$i['count']})" : ''))->implode('; ');
        $tail = $open === '' ? 'No checklist item is open.' : "Open checklist items: {$open}.";

        return match ($this->kind) {
            'overdue' => "{$this->period} ended {$this->workingDays} working day(s) ago and is still open. {$tail}",
            'blocked' => "A close of {$this->period} was refused by the close checklist. {$tail}",
            default => "{$this->period} ends within {$this->workingDays} working day(s). {$tail}",
        };
    }
}
