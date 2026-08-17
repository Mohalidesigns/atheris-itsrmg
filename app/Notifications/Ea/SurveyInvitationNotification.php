<?php

namespace App\Notifications\Ea;

use App\Models\Ea\SurveyResponse;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The magic-link invitation.
 *
 * Deliberately not a BaseNotification subclass: BaseNotification greets
 * `$notifiable->name` and sends on the `database` channel, neither of which
 * exists for an on-demand mail recipient. §5.4 B2 requires responses "from
 * non-licensed users", so the recipient here is frequently an email address
 * with no account behind it — an application owner in a business unit who will
 * never hold a seat.
 */
class SurveyInvitationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public SurveyResponse $response)
    {
    }

    public function via(object $notifiable): array
    {
        // Database notifications only exist for real users.
        return isset($notifiable->id) ? ['database', 'mail'] : ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $survey = $this->response->campaign?->survey;
        $entity = $this->response->entityLabel();
        $closes = $this->response->campaign?->closes_at;

        $mail = (new MailMessage)
            ->subject('Please confirm: '.$entity)
            ->greeting('Hello'.($this->response->recipient_name ? ' '.$this->response->recipient_name : '').',')
            ->line($survey?->description ?: 'We are confirming the architecture record below is still accurate.')
            ->line('**Record:** '.$entity);

        if ($this->response->recipient_role) {
            $mail->line('You are listed as **'.$this->response->recipient_role.'** for this record.');
        }

        $mail->action('Confirm the details', $this->response->responseUrl())
            ->line('The link is personal to you — no login or password is needed.');

        if ($closes) {
            $mail->line('Please respond by '.$closes->format('j F Y').'.');
        }

        return $mail->line('If this record is not yours, use the link to tell us and we will re-route it.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'ea.survey.invitation',
            'module' => 'ea',
            'message' => 'Please confirm the details for '.$this->response->entityLabel().'.',
            'action_url' => $this->response->responseUrl(),
        ];
    }
}
