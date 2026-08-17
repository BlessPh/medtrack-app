<?php

namespace App\Modules\Notification\Services;

use App\Modules\Admission\Models\Admission;
use App\Modules\Auth\Models\User;
use App\Modules\Notification\Notifications\AdmissionCreatedNotification;
use Illuminate\Support\Facades\Log;
use Throwable;

class NotificationService
{
    public function admissionCreated(Admission $admission): void
    {
        $user = User::find($admission->student()->value('user_id'));
        if (! $user) {
            return;
        }

        try {
            $user->notify(new AdmissionCreatedNotification($admission->public_id));
        } catch (Throwable $exception) {
            Log::warning('Échec de notification après admission.', ['admission_id' => $admission->public_id, 'exception' => $exception::class]);
        }
    }
}
