<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

abstract class BaseNotification extends Notification implements ShouldQueue
{
    use Queueable;

    protected string $type;
    protected string $module;
    protected string $actionUrl;

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->getSubject())
            ->greeting("Hello {$notifiable->name},")
            ->line($this->getMessage())
            ->action('View Details', $this->actionUrl)
            ->line('This is an automated notification from IT Risk Mgt.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => $this->type,
            'module' => $this->module,
            'message' => $this->getMessage(),
            'action_url' => $this->actionUrl,
        ];
    }

    abstract protected function getSubject(): string;
    abstract protected function getMessage(): string;
}
