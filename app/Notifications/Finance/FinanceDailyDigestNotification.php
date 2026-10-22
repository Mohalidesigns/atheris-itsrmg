<?php

namespace App\Notifications\Finance;

use App\Notifications\BaseNotification;
use Illuminate\Notifications\Messages\MailMessage;

/** Opt-in daily digest of renewals due and approvals waiting (ATH-FIN-001 §7). Carries no amounts. */
class FinanceDailyDigestNotification extends BaseNotification
{
    protected string $type = 'finance.daily_digest';

    protected string $module = 'IT Financial Management';

    /** @param array<int,array{title:string,lines:array<int,string>}> $sections */
    public function __construct(public array $sections, string $url, public ?string $date = null)
    {
        $this->actionUrl = $url;
        $this->date ??= now(config('finance.timezone'))->format('d M Y');
    }

    protected function getSubject(): string
    {
        return "IT finance digest, {$this->date}";
    }

    protected function getMessage(): string
    {
        return collect($this->sections)->map(fn ($s) => $s['title'].': '.count($s['lines']))->implode('; ').'.';
    }

    public function toMail(object $notifiable): MailMessage
    {
        $m = (new MailMessage)->subject($this->getSubject())->greeting("Hello {$notifiable->name},");
        foreach ($this->sections as $s) {
            $m->line('**'.$s['title'].'**');
            foreach ($s['lines'] as $line) {
                $m->line('- '.$line);
            }
        }

        return $m->action('Open IT Finance', $this->actionUrl)->line('This is an automated notification from Atheris Govern.');
    }

    public function toArray(object $notifiable): array
    {
        return parent::toArray($notifiable) + ['sections' => $this->sections];
    }
}
