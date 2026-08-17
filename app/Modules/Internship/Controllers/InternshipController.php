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

class InternshipController
{
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
        $internship = Internship::create(['admission_id' => $admission->id, 'student_id' => $admission->student_id, 'hospital_id' => $admission->hospital_id, 'path_template_id' => $data['path_template_id'] ?? null, 'supervisor_id' => isset($data['supervisor_id']) ? User::where('public_id', $data['supervisor_id'])->value('id') : null, 'starts_on' => $data['starts_on'], 'status' => 'PLANNED']);

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
