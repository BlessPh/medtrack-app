<?php

namespace App\Modules\Scheduling\Controllers;

use App\Modules\Academic\Models\Student;
use App\Modules\Institution\Models\Institution;
use App\Modules\Internship\Models\Internship;
use App\Modules\Scheduling\Models\AttendanceCorrection;
use App\Modules\Scheduling\Models\AttendanceRecord;
use App\Modules\Scheduling\Models\BiometricDevice;
use App\Modules\Scheduling\Models\Schedule;
use App\Modules\Scheduling\Models\ScheduleEntry;
use App\Shared\Enums\InstitutionRole;
use App\Shared\Services\InstitutionAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SchedulingController
{
    public function storeSchedule(Request $request): JsonResponse
    {
        $data = $request->validate(['internship_id' => ['required', 'uuid', 'exists:internships,public_id'], 'name' => ['required', 'string', 'max:200'], 'starts_on' => ['required', 'date'], 'ends_on' => ['required', 'date', 'after_or_equal:starts_on']]);
        $internship = Internship::where('public_id', $data['internship_id'])->firstOrFail();
        $this->manage($request, $internship->hospital_id);
        $schedule = Schedule::create(['internship_id' => $internship->id, 'name' => $data['name'], 'starts_on' => $data['starts_on'], 'ends_on' => $data['ends_on'], 'status' => 'DRAFT', 'created_by' => $request->user()->id]);

        return response()->json(['data' => $schedule], 201);
    }

    public function storeEntry(Request $request, Schedule $schedule): JsonResponse
    {
        $this->manage($request, $schedule->internship->hospital_id);
        $data = $request->validate(['starts_at' => ['required', 'date'], 'ends_at' => ['required', 'date', 'after:starts_at'], 'activity_type' => ['required', 'string', 'max:30'], 'location' => ['nullable', 'string', 'max:255']]);
        $entry = $schedule->entries()->create($data + ['student_id' => $schedule->internship->student_id, 'status' => 'SCHEDULED']);

        return response()->json(['data' => $entry], 201);
    }

    public function publish(Request $request, Schedule $schedule): JsonResponse
    {
        $this->manage($request, $schedule->internship->hospital_id);
        abort_unless($schedule->status === 'DRAFT' && $schedule->entries()->exists(), 422);
        $schedule->update(['status' => 'PUBLISHED']);

        return response()->json(['data' => $schedule]);
    }

    public function cancel(Request $request, Schedule $schedule): JsonResponse
    {
        $this->manage($request, $schedule->internship->hospital_id);
        abort_if($schedule->status === 'CANCELLED', 409);
        $schedule->update(['status' => 'CANCELLED']);
        $schedule->entries()->where('status', 'SCHEDULED')->update(['status' => 'CANCELLED']);

        return response()->json(['data' => $schedule]);
    }

    public function storeDevice(Request $request): JsonResponse
    {
        $data = $request->validate(['institution_id' => ['required', 'uuid', 'exists:institutions,public_id'], 'code' => ['required', 'string', 'max:100'], 'name' => ['required', 'string', 'max:150'], 'location' => ['nullable', 'string', 'max:255']]);
        $institution = Institution::where('public_id', $data['institution_id'])->firstOrFail();
        $this->manage($request, $institution->id);
        $device = BiometricDevice::create(['institution_id' => $institution->id, 'code' => $data['code'], 'name' => $data['name'], 'location' => $data['location'] ?? null, 'status' => 'ACTIVE']);

        return response()->json(['data' => $device], 201);
    }

    public function record(Request $request): JsonResponse
    {
        $data = $request->validate(['student_id' => ['required', 'uuid', 'exists:students,public_id'], 'schedule_entry_id' => ['nullable', 'required_if:source,MANUAL', 'integer', 'exists:schedule_entries,id'], 'type' => ['required', 'in:CHECK_IN,CHECK_OUT,ABSENCE,LATE'], 'recorded_at' => ['required', 'date', 'before_or_equal:now'], 'source' => ['required', 'in:MANUAL,BIOMETRIC'], 'device_code' => ['required_if:source,BIOMETRIC', 'nullable', 'string'], 'notes' => ['nullable', 'string', 'max:1000']]);
        $student = Student::where('public_id', $data['student_id'])->firstOrFail();
        $device = null;
        if ($data['source'] === 'BIOMETRIC') {
            $device = BiometricDevice::where('code', $data['device_code'])->where('status', 'ACTIVE')->firstOrFail();
            $this->manage($request, $device->institution_id);
            $device->update(['last_seen_at' => now()]);
        } else {
            $entry = ScheduleEntry::findOrFail($data['schedule_entry_id']);
            $this->manage($request, $entry->schedule->internship->hospital_id);
            if (! app(InstitutionAccess::class)->has($request->user(), $entry->schedule->internship->hospital_id, [InstitutionRole::HospitalManager->value])) {
                abort_unless($entry->schedule->internship->supervisor_id === $request->user()->id, 403);
            }
        }
        abort_if(AttendanceRecord::where('student_id', $student->id)->where('type', $data['type'])->where('recorded_at', $data['recorded_at'])->exists(), 422, 'Pointage déjà enregistré.');
        $record = AttendanceRecord::create(['student_id' => $student->id, 'schedule_entry_id' => $data['schedule_entry_id'] ?? null, 'biometric_device_id' => $device?->id, 'type' => $data['type'], 'recorded_at' => $data['recorded_at'], 'source' => $data['source'], 'status' => 'VALID', 'notes' => $data['notes'] ?? null, 'recorded_by' => $request->user()->id]);

        return response()->json(['data' => $record], 201);
    }

    public function supervisorHistory(Request $request): JsonResponse
    {
        $data = $request->validate(['hospital_id' => ['required', 'uuid']]);
        $hospital = Institution::where('public_id', $data['hospital_id'])->where('type', 'HOSPITAL')->firstOrFail();
        abort_unless(app(InstitutionAccess::class)->has($request->user(), $hospital->id, [InstitutionRole::Supervisor->value]), 403);
        $records = AttendanceRecord::whereHas('scheduleEntry.schedule.internship', fn ($query) => $query->where('hospital_id', $hospital->id)->where('supervisor_id', $request->user()->id))
            ->with(['student.user', 'scheduleEntry.schedule'])->latest('recorded_at')->paginate(min($request->integer('per_page', 50), 100));
        return response()->json(['data' => $records]);
    }

    public function correction(Request $request, AttendanceRecord $record): JsonResponse
    {
        abort_unless($record->student->user_id === $request->user()->id, 403);
        $data = $request->validate(['corrected_at' => ['required', 'date'], 'reason' => ['required', 'string', 'max:2000']]);

        return response()->json(['data' => $record->corrections()->create($data + ['requested_by' => $request->user()->id, 'status' => 'PENDING'])], 201);
    }

    public function reviewCorrection(Request $request, AttendanceCorrection $correction): JsonResponse
    {
        $hospitalId = $correction->attendanceRecord->scheduleEntry->schedule->internship->hospital_id;
        $this->manage($request, $hospitalId);
        $data = $request->validate(['status' => ['required', 'in:APPROVED,REJECTED'], 'review_note' => ['nullable', 'string', 'max:2000']]);
        abort_unless($correction->status === 'PENDING', 409);
        $correction->update($data + ['reviewed_by' => $request->user()->id, 'reviewed_at' => now()]);
        if ($data['status'] === 'APPROVED') {
            $correction->attendanceRecord()->update(['recorded_at' => $correction->corrected_at, 'status' => 'CORRECTED']);
        }

        return response()->json(['data' => $correction]);
    }

    public function summary(Request $request, Student $student): JsonResponse
    {
        abort_unless($student->user_id === $request->user()->id || app(InstitutionAccess::class)->isSuperAdmin($request->user()), 403);
        $records = $student->attendanceRecords()->whereIn('status', ['VALID', 'CORRECTED']);

        return response()->json(['data' => ['total' => (clone $records)->count(), 'check_ins' => (clone $records)->where('type', 'CHECK_IN')->count(), 'check_outs' => (clone $records)->where('type', 'CHECK_OUT')->count()]]);
    }

    private function manage(Request $request, int $hospitalId): void
    {
        abort_unless(app(InstitutionAccess::class)->has($request->user(), $hospitalId, [InstitutionRole::HospitalManager->value, InstitutionRole::Supervisor->value]), 403);
    }
}
