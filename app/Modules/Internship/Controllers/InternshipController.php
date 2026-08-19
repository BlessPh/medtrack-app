<?php

namespace App\Modules\Internship\Controllers;

use App\Modules\Admission\Models\Admission;
use App\Modules\Auth\Models\User;
use App\Modules\Institution\Models\InstitutionUnit;
use App\Modules\Internship\Models\Internship;
use App\Modules\Internship\Models\PathTemplate;
use App\Modules\Internship\Models\Rotation;
use App\Shared\Enums\InstitutionRole;
use App\Shared\Services\InstitutionAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Modules\Institution\Models\Institution;
use App\Modules\Notification\Notifications\InstitutionNotification;
use Illuminate\Support\Facades\Notification;

class InternshipController
{
    public function dashboard(Request $request, InstitutionAccess $access): JsonResponse
    {
        $data = $request->validate(['hospital_id' => ['required', 'uuid']]);
        $hospital = Institution::where('public_id', $data['hospital_id'])->where('type', 'HOSPITAL')->firstOrFail();
        abort_unless($access->has($request->user(), $hospital->id, [InstitutionRole::HospitalManager->value]), 403);
        $capacity = DB::table('capacity_pools')->join('campaign_hospitals', 'campaign_hospitals.id', '=', 'capacity_pools.campaign_hospital_id')->where('campaign_hospitals.hospital_id', $hospital->id)
            ->selectRaw('COALESCE(SUM(total_places),0) total, COALESCE(SUM(reserved_places),0) reserved')->first();
        $services = DB::table('institution_units')->where('institution_id', $hospital->id)->where('type', 'SERVICE')->whereNull('parent_id')->get(['id', 'name']);
        $loads = DB::table('applications')->where('preferred_hospital_id', $hospital->id)->whereNotNull('assigned_service_id')->whereIn('status', ['ACCEPTED', 'UNDER_REVIEW'])
            ->selectRaw('assigned_service_id, COUNT(*) total')->groupBy('assigned_service_id')->pluck('total', 'assigned_service_id');

        return response()->json(['data' => [
            'statistics' => [
                'planned' => Internship::where('hospital_id', $hospital->id)->where('status', 'PLANNED')->count(),
                'active' => Internship::where('hospital_id', $hospital->id)->where('status', 'ACTIVE')->count(),
                'completed' => Internship::where('hospital_id', $hospital->id)->where('status', 'COMPLETED')->count(),
                'pending_applications' => DB::table('applications')->where('preferred_hospital_id', $hospital->id)->whereIn('status', ['SUBMITTED', 'UNDER_REVIEW'])->count(),
                'pending_d4' => DB::table('campaign_hospitals')->where('hospital_id', $hospital->id)->where('request_status', 'PENDING')->count(),
                'capacity_total' => (int) ($capacity->total ?? 0), 'capacity_reserved' => (int) ($capacity->reserved ?? 0),
                'capacity_available' => max(0, (int) ($capacity->total ?? 0) - (int) ($capacity->reserved ?? 0)),
                'rotations_to_start' => DB::table('rotations')->join('internships', 'internships.id', '=', 'rotations.internship_id')->where('internships.hospital_id', $hospital->id)->where('rotations.status', 'PLANNED')->whereDate('rotations.starts_on', '<=', today())->count(),
                'rotations_to_close' => DB::table('rotations')->join('internships', 'internships.id', '=', 'rotations.internship_id')->where('internships.hospital_id', $hospital->id)->where('rotations.status', 'ACTIVE')->whereDate('rotations.ends_on', '<=', today())->count(),
            ],
            'service_loads' => $services->map(fn ($service) => ['id' => $service->id, 'name' => $service->name, 'current_load' => (int) ($loads[$service->id] ?? 0)]),
            'recent_internships' => Internship::where('hospital_id', $hospital->id)->with(['student.user', 'supervisor', 'rotations'])->latest()->limit(8)->get(),
            'recent_notifications' => $request->user()->notifications()->latest()->limit(6)->get(),
        ]]);
    }

    /** Return the active rotation paths available to hospital managers. */
    public function templates(Request $request, InstitutionAccess $access): JsonResponse
    {
        $data = $request->validate(['hospital_id' => ['required', 'uuid']]);
        $hospital = Institution::where('public_id', $data['hospital_id'])->where('type', 'HOSPITAL')->firstOrFail();
        abort_unless($access->has($request->user(), $hospital->id, [InstitutionRole::HospitalManager->value]), 403);

        return response()->json(['data' => PathTemplate::with('steps')->where('status', 'ACTIVE')->orderBy('name')->get()]);
    }

    public function index(Request $request, InstitutionAccess $access): JsonResponse
    {
        $data = $request->validate(['hospital_id' => ['required', 'uuid'], 'status' => ['nullable', 'string']]);
        $hospital = Institution::where('public_id', $data['hospital_id'])->where('type', 'HOSPITAL')->firstOrFail();
        abort_unless($access->has($request->user(), $hospital->id, [InstitutionRole::HospitalManager->value]), 403);
        $query = Internship::where('hospital_id', $hospital->id)->with(['student.user', 'supervisor', 'pathTemplate.steps', 'rotations.evaluations']);
        $query->when($data['status'] ?? null, fn ($query, $status) => $query->where('status', $status));
        return response()->json(['data' => $query->latest()->paginate(min($request->integer('per_page', 25), 100))]);
    }

    /** Aggregate attendance and evaluation progress for the manager's hospital. */
    public function monitoring(Request $request, InstitutionAccess $access): JsonResponse
    {
        $data = $request->validate(['hospital_id' => ['required', 'uuid']]);
        $hospital = Institution::where('public_id', $data['hospital_id'])->where('type', 'HOSPITAL')->firstOrFail();
        abort_unless($access->has($request->user(), $hospital->id, [InstitutionRole::HospitalManager->value]), 403);

        $internships = Internship::where('hospital_id', $hospital->id)
            ->with(['student.user', 'supervisor', 'rotations.evaluations'])
            ->withCount('rotations')
            ->latest()
            ->get()
            ->map(function (Internship $internship): array {
                $attendance = DB::table('attendance_records')
                    ->join('schedule_entries', 'schedule_entries.id', '=', 'attendance_records.schedule_entry_id')
                    ->join('schedules', 'schedules.id', '=', 'schedule_entries.schedule_id')
                    ->where('schedules.internship_id', $internship->id)
                    ->whereIn('attendance_records.status', ['VALID', 'CORRECTED']);
                $evaluations = $internship->rotations->flatMap->evaluations;

                return [
                    'id' => $internship->public_id,
                    'student_name' => $internship->student->user?->name ?? $internship->student->student_number,
                    'student_number' => $internship->student->student_number,
                    'supervisor_name' => $internship->supervisor?->name,
                    'status' => $internship->status,
                    'rotations' => $internship->rotations_count,
                    'check_ins' => (clone $attendance)->where('attendance_records.type', 'CHECK_IN')->count(),
                    'check_outs' => (clone $attendance)->where('attendance_records.type', 'CHECK_OUT')->count(),
                    'evaluations_total' => $evaluations->count(),
                    'evaluations_finalized' => $evaluations->where('status', 'FINALIZED')->count(),
                    'average_score' => $evaluations->where('status', 'FINALIZED')->avg('score'),
                ];
            });

        return response()->json(['data' => $internships]);
    }

    public function update(Request $request, Internship $internship, InstitutionAccess $access): JsonResponse
    {
        abort_unless($access->has($request->user(), $internship->hospital_id, [InstitutionRole::HospitalManager->value]), 403);
        abort_if(in_array($internship->status, ['COMPLETED', 'CANCELLED'], true), 409, 'Ce stage ne peut plus être modifié.');
        $data = $request->validate(['supervisor_id' => ['nullable', 'uuid'], 'path_template_id' => ['nullable', 'integer', 'exists:path_templates,id'], 'starts_on' => ['required', 'date'], 'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on']]);
        if (isset($data['supervisor_id'])) {
            $supervisor = User::where('public_id', $data['supervisor_id'])->firstOrFail();
            abort_unless($access->has($supervisor, $internship->hospital_id, [InstitutionRole::Supervisor->value]), 422, 'Superviseur invalide.');
            $data['supervisor_id'] = $supervisor->id;
        }
        $internship->update($data);
        return response()->json(['data' => $internship->fresh()->load(['student.user', 'supervisor', 'rotations'])]);
    }

    public function notify(Request $request, Internship $internship, InstitutionAccess $access): JsonResponse
    {
        abort_unless($access->has($request->user(), $internship->hospital_id, [InstitutionRole::HospitalManager->value]), 403);
        $data = $request->validate(['title' => ['required', 'string', 'max:120'], 'message' => ['required', 'string', 'max:1000'], 'recipients' => ['required', 'in:STUDENT,SUPERVISOR,BOTH']]);
        $recipients = collect();
        if (in_array($data['recipients'], ['STUDENT', 'BOTH'], true) && $internship->student->user) $recipients->push($internship->student->user);
        if (in_array($data['recipients'], ['SUPERVISOR', 'BOTH'], true) && $internship->supervisor) $recipients->push($internship->supervisor);
        $hospital = Institution::findOrFail($internship->hospital_id);
        Notification::send($recipients->unique('id'), new InstitutionNotification($hospital->public_id, $hospital->name, $data['title'], $data['message'], 'INTERNSHIP', 'INFO'));
        return response()->json(['message' => 'Notification envoyée.', 'data' => ['recipients_count' => $recipients->unique('id')->count()]], 201);
    }
    public function storeTemplate(Request $request): JsonResponse
    {
        abort_unless(app(InstitutionAccess::class)->isSuperAdmin($request->user()), 403);
        $data = $request->validate(['name' => ['required', 'string', 'max:200'], 'steps' => ['required', 'array', 'min:1'], 'steps.*.name' => ['required', 'string'], 'steps.*.duration_days' => ['required', 'integer', 'min:1']]);
        $template = DB::transaction(function () use ($data): PathTemplate {
            $template = PathTemplate::create(['name' => $data['name'], 'status' => 'ACTIVE']);
            foreach ($data['steps'] as $position => $step) {
                $template->steps()->create($step + ['position' => $position + 1]);
            }

            return $template;
        });

        return response()->json(['data' => $template->load('steps')], 201);
    }

    public function store(Request $request, InstitutionAccess $access): JsonResponse
    {
        $data = $request->validate(['admission_id' => ['required', 'uuid', 'exists:admissions,public_id'], 'path_template_id' => ['nullable', 'integer', 'exists:path_templates,id'], 'supervisor_id' => ['nullable', 'uuid', 'exists:users,public_id'], 'starts_on' => ['required', 'date']]);
        $admission = Admission::where('public_id', $data['admission_id'])->firstOrFail();
        abort_unless($access->has($request->user(), $admission->hospital_id, [InstitutionRole::HospitalManager->value]), 403);
        abort_if(Internship::where('admission_id', $admission->id)->exists(), 409, 'Un stage existe déjà pour cette admission.');
        $supervisorId = null;
        if (isset($data['supervisor_id'])) {
            $supervisor = User::where('public_id', $data['supervisor_id'])->firstOrFail();
            abort_unless($access->has($supervisor, $admission->hospital_id, [InstitutionRole::Supervisor->value]), 422, 'Superviseur invalide.');
            $supervisorId = $supervisor->id;
        }
        $internship = Internship::create(['admission_id' => $admission->id, 'student_id' => $admission->student_id, 'hospital_id' => $admission->hospital_id, 'path_template_id' => $data['path_template_id'] ?? null, 'supervisor_id' => $supervisorId, 'starts_on' => $data['starts_on'], 'status' => 'PLANNED']);

        return response()->json(['data' => $internship], 201);
    }

    public function storeRotation(Request $request, Internship $internship, InstitutionAccess $access): JsonResponse
    {
        abort_unless($access->has($request->user(), $internship->hospital_id, [InstitutionRole::HospitalManager->value]), 403);
        $data = $request->validate(['path_step_id' => ['nullable', 'integer', 'exists:path_steps,id'], 'institution_unit_id' => ['nullable', 'integer', 'exists:institution_units,id'], 'starts_on' => ['required', 'date'], 'ends_on' => ['required', 'date', 'after_or_equal:starts_on']]);
        if (isset($data['institution_unit_id'])) {
            abort_unless(InstitutionUnit::whereKey($data['institution_unit_id'])->where('institution_id', $internship->hospital_id)->exists(), 422);
        }
        $rotation = $internship->rotations()->create($data + ['status' => 'PLANNED']);

        return response()->json(['data' => $rotation], 201);
    }

    public function status(Request $request, Internship $internship): JsonResponse
    {
        abort_unless(app(InstitutionAccess::class)->has($request->user(), $internship->hospital_id, [InstitutionRole::HospitalManager->value]), 403);
        $data = $request->validate(['status' => ['required', 'in:ACTIVE,COMPLETED,CANCELLED']]);
        $allowed = ['PLANNED' => ['ACTIVE', 'CANCELLED'], 'ACTIVE' => ['COMPLETED', 'CANCELLED']];
        abort_unless(in_array($data['status'], $allowed[$internship->status] ?? [], true), 409);
        if ($data['status'] === 'COMPLETED') {
            abort_if($internship->rotations()->whereNot('status', 'COMPLETED')->exists(), 422);
        }
        $internship->update($data + ['ends_on' => $data['status'] === 'COMPLETED' ? now()->toDateString() : $internship->ends_on]);

        return response()->json(['data' => $internship]);
    }

    public function extend(Request $request, Rotation $rotation): JsonResponse
    {
        abort_unless(app(InstitutionAccess::class)->has($request->user(), $rotation->internship->hospital_id, [InstitutionRole::HospitalManager->value]), 403);
        $data = $request->validate(['new_end_date' => ['required', 'date', 'after:'.$rotation->ends_on->toDateString()], 'reason' => ['required', 'string', 'max:2000']]);
        $extension = $rotation->extensions()->create(['previous_end_date' => $rotation->ends_on, 'new_end_date' => $data['new_end_date'], 'reason' => $data['reason'], 'approved_by' => $request->user()->id]);
        $rotation->update(['ends_on' => $data['new_end_date']]);

        return response()->json(['data' => $extension], 201);
    }

    public function rotationStatus(Request $request, Rotation $rotation): JsonResponse
    {
        abort_unless(app(InstitutionAccess::class)->has($request->user(), $rotation->internship->hospital_id, [InstitutionRole::HospitalManager->value, InstitutionRole::Supervisor->value]), 403);
        $data = $request->validate(['status' => ['required', 'in:ACTIVE,COMPLETED,CANCELLED']]);
        $allowed = ['PLANNED' => ['ACTIVE', 'CANCELLED'], 'ACTIVE' => ['COMPLETED', 'CANCELLED']];
        abort_unless(in_array($data['status'], $allowed[$rotation->status] ?? [], true), 409);
        $rotation->update($data);

        return response()->json(['data' => $rotation]);
    }
}
