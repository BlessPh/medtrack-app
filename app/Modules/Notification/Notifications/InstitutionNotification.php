<?php

namespace App\Modules\Notification\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

class InstitutionNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly string $institutionId,
        private readonly string $institutionName,
        private readonly string $title,
        private readonly string $message,
        private readonly string $category = 'INSTITUTION',
        private readonly string $severity = 'INFO',
        private readonly ?string $url = null,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'broadcast'];
    }

    public function toArray(object $notifiable): array
    {
        return ['institution_id' => $this->institutionId, 'institution_name' => $this->institutionName, 'title' => $this->title, 'message' => $this->message, 'category' => $this->category, 'severity' => $this->severity, 'url' => $this->url];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toArray($notifiable));
    }
}
