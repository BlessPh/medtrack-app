<?php

namespace App\Modules\Institution\Services;

use App\Modules\Institution\Models\Institution;
use App\Modules\Institution\Models\InstitutionAuditLog;
use Illuminate\Http\Request;

class InstitutionAudit
{
    public function record(Request $request, Institution $institution, string $action, string $subjectType, string|int|null $subjectId = null, ?array $before = null, ?array $after = null): void
    {
        InstitutionAuditLog::create([
            'institution_id' => $institution->id,
            'actor_user_id' => $request->user()?->id,
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'before' => $before,
            'after' => $after,
            'ip_address' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 500),
            'created_at' => now(),
        ]);
    }
}
