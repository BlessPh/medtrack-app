<?php

namespace App\Modules\Institution\Notifications;

use App\Modules\Institution\Models\InstitutionMemberInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class InstitutionMemberInvitationNotification extends Notification
{
    use Queueable;

    public function __construct(private readonly InstitutionMemberInvitation $invitation, private readonly string $token) {}

    public function via(object $notifiable): array { return ['mail']; }

    public function toMail(object $notifiable): MailMessage
    {
        $url = rtrim((string) config('app.frontend_url', env('FRONTEND_URL', 'http://localhost:3000')), '/').'/member-invitation/'.$this->token;

        return (new MailMessage)->subject('Invitation à rejoindre '.$this->invitation->institution->name)
            ->greeting('Bonjour,')
            ->line("Vous êtes invité à rejoindre {$this->invitation->institution->name} sur MedTrack.")
            ->line('Le rôle prévu pour votre compte est : '.$this->invitation->role.'.')
            ->action('Créer mon compte', $url)
            ->line('Cette invitation expire le '.$this->invitation->expires_at->format('d/m/Y à H:i').'.')
            ->line('Ignorez ce message si vous ne reconnaissez pas cette invitation.');
    }
}
