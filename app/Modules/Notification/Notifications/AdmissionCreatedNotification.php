<?php

namespace App\Modules\Notification\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

class AdmissionCreatedNotification extends Notification
{
    use Queueable;

    public function __construct(private readonly string $admissionId) {}

    public function via(object $notifiable): array
    {
        return ['database', 'broadcast', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject('Admission à un stage')->line('Votre admission à un stage a été confirmée.')->line('Référence : '.$this->admissionId);
    }

    public function toArray(object $notifiable): array
    {
        return ['admission_id' => $this->admissionId, 'message' => 'Votre admission a été confirmée.'];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toArray($notifiable));
    }
}
