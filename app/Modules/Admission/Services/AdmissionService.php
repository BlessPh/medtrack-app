<?php

namespace App\Modules\Admission\Services;

use App\Modules\Admission\Models\Admission;
use App\Modules\Admission\Models\Application;
use App\Modules\Admission\Models\CapacityPool;
use App\Modules\Admission\Models\CapacityReservation;
use App\Modules\Notification\Services\NotificationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AdmissionService
{
    public function __construct(private readonly NotificationService $notifications) {}

    public function accept(Application $application, CapacityPool $pool): Admission
    {
        $admission = DB::transaction(function () use ($application, $pool): Admission {
            $application = Application::query()->lockForUpdate()->findOrFail($application->id);
            $pool = CapacityPool::query()->lockForUpdate()->findOrFail($pool->id);
            if ($application->status !== 'SUBMITTED' || $application->admission()->exists()) {
                throw ValidationException::withMessages(['application' => 'Cette candidature ne peut plus être admise.']);
            }
            if ($pool->reserved_places >= $pool->total_places) {
                throw ValidationException::withMessages(['capacity' => 'Aucune place disponible.']);
            }
            $hospital = $pool->campaignHospital;
            if ($hospital->campaign_id !== $application->campaign_id) {
                throw ValidationException::withMessages(['capacity' => 'Cette capacité ne concerne pas la campagne.']);
            }
            $pool->increment('reserved_places');
            CapacityReservation::create(['capacity_pool_id' => $pool->id, 'application_id' => $application->id, 'status' => 'CONFIRMED']);
            $application->update(['status' => 'ACCEPTED', 'reviewed_at' => now()]);

            return Admission::create(['application_id' => $application->id, 'student_id' => $application->student_id, 'hospital_id' => $hospital->hospital_id, 'status' => 'ACCEPTED', 'admitted_at' => now()]);
        }, 3);
        $this->notifications->admissionCreated($admission);

        return $admission;
    }
}
