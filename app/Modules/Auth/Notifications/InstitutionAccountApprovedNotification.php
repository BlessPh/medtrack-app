<?php

namespace App\Modules\Auth\Notifications;

use App\Modules\Auth\Models\InstitutionAccountRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

class InstitutionAccountApprovedNotification extends Notification
{
    use Queueable;

    public function __construct(private readonly InstitutionAccountRequest $accountRequest) {}

    public function via(object $notifiable): array
    {
        return ['database', 'broadcast'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'INSTITUTION_ACCOUNT_APPROVED',
            'title' => 'Demande approuvée',
            'message' => 'Votre compte administrateur institutionnel est maintenant actif.',
            'reference' => $this->accountRequest->reference,
            'email_delivery' => 'PENDING_IMPLEMENTATION',
        ];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toArray($notifiable));
    }
}
