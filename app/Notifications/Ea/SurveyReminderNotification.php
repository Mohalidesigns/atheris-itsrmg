<?php

namespace App\Notifications\Ea;

use App\Models\Ea\SurveyResponse;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Reminder to a non-respondent. Ardoq reminds at 7–14 day intervals; the
 * interval and the cap are per-survey settings so a quarterly campaign does not
 * nag at the cadence of a weekly one.
 */
class SurveyReminderNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public SurveyResponse $response)
    {
    }

    public function via(object $notifiable): array
    {
        return isset($notifiable->id) ? ['database', 'mail'] : ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $entity = $this->response->entityLabel();
        $closes = $this->response->campaign?->closes_at;
        $days = $closes ? (int) round(now()->diffInDays($closes, false)) : null;

        $mail = (new MailMessage)
            ->subject('Reminder: please confirm '.$entity)
            ->greeting('Hello'.($this->response->recipient_name ? ' '.$this->response->recipient_name : '').',')
            ->line('We have not yet heard back about this architecture record.')
            ->line('**Record:** '.$entity);

        if ($days !== null) {
            $mail->line($days > 0
                ? "There are {$days} day(s) left to respond."
                : 'The response window closes today.');
        }

        return $mail
            ->action('Confirm the details', $this->response->responseUrl())
            ->line('It usually takes under a minute.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'ea.survey.reminder',
            'module' => 'ea',
            'message' => 'Reminder — please confirm the details for '.$this->response->entityLabel().'.',
            'action_url' => $this->response->responseUrl(),
        ];
    }
}
