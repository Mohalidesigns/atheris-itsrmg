<?php

namespace App\Notifications\Finance;

use App\Notifications\BaseNotification;

/** A cloud cost day broke its baseline (ATH-FIN-001 §7, FIN-03.10). Carries the deviation percentage only, never amounts. */
class CloudAnomalyNotification extends BaseNotification
{
    protected string $type = 'finance.cloud_anomaly';

    protected string $module = 'IT Financial Management';

    public function __construct(public string $account, public string $scopeLabel, public string $detectedOn, public string $deviationPct, public string $method, string $url)
    {
        $this->actionUrl = $url;
    }

    protected function getSubject(): string
    {
        return "Cloud cost anomaly: {$this->account} ({$this->scopeLabel})";
    }

    protected function getMessage(): string
    {
        $basis = $this->method === 'week_over_week' ? 'the same weekday last week' : 'the previous seven-day average';

        return "Cloud spend on {$this->account} ({$this->scopeLabel}) was {$this->deviationPct}% above {$basis} on {$this->detectedOn}. Review it and acknowledge, resolve or dismiss the anomaly.";
    }
}
