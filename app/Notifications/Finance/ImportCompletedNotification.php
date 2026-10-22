<?php

namespace App\Notifications\Finance;

use App\Notifications\BaseNotification;

/** A finance CSV import finished (ATH-FIN-001 §7, FIN-03.15). Carries counts only. */
class ImportCompletedNotification extends BaseNotification
{
    protected string $type = 'finance.import_completed';

    protected string $module = 'IT Financial Management';

    public function __construct(public string $importType, public string $fileName, public int $accepted, public int $rejected, public int $unmatched, string $url)
    {
        $this->actionUrl = $url;
    }

    protected function getSubject(): string
    {
        return "Finance import completed: {$this->fileName}";
    }

    protected function getMessage(): string
    {
        return "The {$this->importType} import of {$this->fileName} finished: {$this->accepted} accepted, {$this->rejected} rejected, {$this->unmatched} unmatched.";
    }
}
