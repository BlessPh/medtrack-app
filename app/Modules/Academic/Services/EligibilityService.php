<?php

namespace App\Modules\Academic\Services;

use App\Modules\Academic\Models\Campaign;
use App\Modules\Academic\Models\Student;

class EligibilityService
{
    public function isEligible(Student $student, Campaign $campaign): bool
    {
        if ($student->status !== 'ACTIVE' || $campaign->status !== 'OPEN' || now()->gt($campaign->ends_at)) {
            return false;
        }

        return $student->enrollments()->where('status', 'ACTIVE')->whereHas('promotion', fn ($query) => $query->whereHas('campaigns', fn ($campaignQuery) => $campaignQuery->whereKey($campaign)))->exists();
    }
}
