<?php

namespace App\Modules\Internship\Controllers;

use App\Modules\Institution\Models\HospitalSupervisorProfile;
use App\Modules\Institution\Models\Institution;
use App\Modules\Internship\Models\Internship;
use App\Shared\Enums\InstitutionRole;
use App\Shared\Services\InstitutionAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class SupervisorController
{
    public function dashboard(Request $request, InstitutionAccess $access): JsonResponse
    {
        $hospital = $this->hospital($request, $access);
        $internships = Internship::where('hospital_id', $hospital->id)->where('supervisor_id', $request->user()->id)
            ->with(['student.user.profile', 'student.university', 'student.enrollments.promotion.program', 'student.enrollments.promotion.level', 'admission.application.campaign', 'pathTemplate.steps', 'rotations.evaluations', 'observations'])
            ->latest()->get();
        $ids = $internships->pluck('id');
        $entries = DB::table('schedule_entries')->join('schedules', 'schedules.id', '=', 'schedule_entries.schedule_id')->join('students', 'students.id', '=', 'schedule_entries.student_id')->whereIn('schedules.internship_id', $ids)
            ->where('schedule_entries.status', 'SCHEDULED')->orderBy('starts_at')->get(['schedule_entries.*', 'schedules.internship_id', 'students.public_id as student_public_id']);
        $attendance = DB::table('attendance_records')->join('schedule_entries', 'schedule_entries.id', '=', 'attendance_records.schedule_entry_id')->join('schedules', 'schedules.id', '=', 'schedule_entries.schedule_id')
            ->whereIn('schedules.internship_id', $ids)->latest('attendance_records.recorded_at')->get(['attendance_records.*', 'schedules.internship_id']);
        $profile = HospitalSupervisorProfile::firstOrCreate(['institution_id' => $hospital->id, 'user_id' => $request->user()->id], ['availability_status' => 'AVAILABLE', 'stages_enabled' => true]);
        $profile->load('services:id,name,code,status');

        return response()->json(['data' => [
            'statistics' => ['students' => $internships->whereIn('status', ['PLANNED', 'ACTIVE'])->pluck('student_id')->unique()->count(), 'active_rotations' => $internships->flatMap->rotations->where('status', 'ACTIVE')->count(), 'pending_evaluations' => $internships->flatMap->rotations->filter(fn ($rotation) => ! $rotation->evaluations->where('evaluator_id', $request->user()->id)->where('status', 'FINALIZED')->count())->count(), 'attendance_to_verify' => $entries->where('starts_at', '<=', now())->count() - $attendance->count()],
            'internships' => $internships, 'activities' => $entries->take(100)->values(), 'upcoming_activities' => $entries->where('starts_at', '>=', now())->take(10)->values(), 'attendance' => $attendance,
            'profile' => $profile, 'notifications' => $request->user()->notifications()->latest()->limit(8)->get(),
        ]]);
    }

    public function observe(Request $request, Internship $internship, InstitutionAccess $access): JsonResponse
    {
        $this->owns($request, $internship, $access);
        $data = $request->validate(['content' => ['required', 'string', 'max:3000']]);
        return response()->json(['data' => $internship->observations()->create($data + ['supervisor_id' => $request->user()->id])], 201);
    }

    public function availability(Request $request, InstitutionAccess $access): JsonResponse
    {
        $hospital = $this->hospital($request, $access);
        $data = $request->validate(['availability_status' => ['required', Rule::in(['AVAILABLE', 'LIMITED', 'UNAVAILABLE'])], 'availability_note' => ['nullable', 'string', 'max:1000'], 'stages_enabled' => ['required', 'boolean']]);
        $profile = HospitalSupervisorProfile::updateOrCreate(['institution_id' => $hospital->id, 'user_id' => $request->user()->id], $data);
        return response()->json(['data' => $profile->load('services:id,name,code,status')]);
    }

    private function hospital(Request $request, InstitutionAccess $access): Institution
    {
        $data = $request->validate(['hospital_id' => ['required', 'uuid']]);
        $hospital = Institution::where('public_id', $data['hospital_id'])->where('type', 'HOSPITAL')->firstOrFail();
        abort_unless($access->has($request->user(), $hospital->id, [InstitutionRole::Supervisor->value]), 403);
        return $hospital;
    }

    private function owns(Request $request, Internship $internship, InstitutionAccess $access): void
    {
        abort_unless($internship->supervisor_id === $request->user()->id && $access->has($request->user(), $internship->hospital_id, [InstitutionRole::Supervisor->value]), 403);
    }
}
