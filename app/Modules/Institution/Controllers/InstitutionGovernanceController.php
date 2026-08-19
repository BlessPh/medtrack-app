<?php

namespace App\Modules\Institution\Controllers;

use App\Modules\Institution\Models\Institution;
use App\Modules\Institution\Models\InstitutionAuditLog;
use App\Shared\Enums\InstitutionRole;
use App\Shared\Services\InstitutionAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InstitutionGovernanceController
{
    public function auditLogs(Request $request, Institution $institution): JsonResponse
    {
        $request->user()->can('manageMembers', $institution) || abort(403);
        $logs = InstitutionAuditLog::query()->where('institution_id', $institution->id)
            ->leftJoin('users', 'users.id', '=', 'institution_audit_logs.actor_user_id')
            ->select('institution_audit_logs.*', 'users.name as actor_name')
            ->latest('institution_audit_logs.created_at')->paginate(min($request->integer('per_page', 30), 100));

        return response()->json(['data' => $logs]);
    }

    public function oversight(Request $request, Institution $institution): JsonResponse
    {
        $request->user()->can('manageMembers', $institution) || abort(403);
        abort_unless($institution->type === 'HOSPITAL', 422, 'Cette vue est réservée aux hôpitaux.');

        $internships = DB::table('internships')->join('students', 'students.id', '=', 'internships.student_id')
            ->leftJoin('users', 'users.id', '=', 'students.user_id')->leftJoin('users as supervisors', 'supervisors.id', '=', 'internships.supervisor_id')
            ->where('internships.hospital_id', $institution->id)
            ->select('internships.public_id as id', 'internships.status', 'internships.starts_on', 'internships.ends_on', 'students.student_number', 'users.name as student_name', 'supervisors.name as supervisor_name')
            ->latest('internships.created_at')->limit(50)->get();
        $d4Requests = DB::table('campaign_hospitals')->join('campaigns', 'campaigns.id', '=', 'campaign_hospitals.campaign_id')
            ->join('institutions as universities', 'universities.id', '=', 'campaigns.university_id')
            ->where('campaign_hospitals.hospital_id', $institution->id)->where('campaigns.strategy', 'D4_RESERVATION')
            ->select('campaign_hospitals.id', 'campaigns.name as campaign_name', 'universities.name as university_name', 'campaign_hospitals.request_status', 'campaign_hospitals.capacity', 'campaign_hospitals.requested_at')
            ->latest('campaign_hospitals.requested_at')->limit(50)->get();
        $finance = DB::table('financial_obligations')->where('institution_id', $institution->id)
            ->select('currency')->selectRaw('COUNT(*) as obligations_count')->selectRaw('SUM(amount) as total_amount')
            ->selectRaw('SUM(paid_amount) as paid_amount')->selectRaw('SUM(amount - paid_amount) as outstanding_amount')
            ->groupBy('currency')->get();
        $roleIds = DB::table('roles')->where('name', InstitutionRole::Admin->value)->pluck('id');
        $activeAdmins = DB::table('model_has_roles')->join('institution_memberships', function ($join): void {
            $join->on('institution_memberships.user_id', '=', 'model_has_roles.model_id')->on('institution_memberships.institution_id', '=', 'model_has_roles.institution_id');
        })->where('model_has_roles.institution_id', $institution->id)->whereIn('model_has_roles.role_id', $roleIds)
            ->where('institution_memberships.status', 'ACTIVE')->distinct()->count('model_has_roles.model_id');

        return response()->json(['data' => [
            'internships' => $internships, 'd4_requests' => $d4Requests, 'finance' => $finance,
            'security' => [
                'active_admins' => $activeAdmins,
                'active_members' => $institution->members()->wherePivot('status', 'ACTIVE')->count(),
                'suspended_members' => $institution->members()->wherePivot('status', 'SUSPENDED')->count(),
                'institution_status' => $institution->status,
                'status_managed_by_super_admin' => true,
            ],
        ]]);
    }
}
