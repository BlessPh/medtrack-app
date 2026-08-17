<?php

namespace App\Modules\Assessment\Controllers;

use App\Modules\Assessment\Models\AcademicDecision;
use App\Modules\Assessment\Models\Evaluation;
use App\Modules\Assessment\Models\EvaluationDispute;
use App\Modules\Assessment\Models\EvaluationTemplate;
use App\Modules\Institution\Models\Institution;
use App\Modules\Internship\Models\Internship;
use App\Modules\Internship\Models\Rotation;
use App\Shared\Enums\InstitutionRole;
use App\Shared\Services\InstitutionAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AssessmentController
{
    public function storeTemplate(Request $request): JsonResponse
    {
        $data = $request->validate(['institution_id' => ['required', 'uuid', 'exists:institutions,public_id'], 'name' => ['required', 'string', 'max:200'], 'criteria' => ['required', 'array', 'min:1'], 'criteria.*.key' => ['required', 'string'], 'criteria.*.maximum' => ['required', 'numeric', 'gt:0']]);
        $institution = Institution::where('public_id', $data['institution_id'])->firstOrFail();
        abort_unless(app(InstitutionAccess::class)->has($request->user(), $institution->id, [InstitutionRole::HospitalManager->value]), 403);
        $maximum = collect($data['criteria'])->sum('maximum');
        $template = EvaluationTemplate::create(['institution_id' => $institution->id, 'name' => $data['name'], 'criteria' => $data['criteria'], 'maximum_score' => $maximum, 'status' => 'ACTIVE']);

        return response()->json(['data' => $template], 201);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['rotation_id' => ['required', 'integer', 'exists:rotations,id'], 'template_id' => ['required', 'integer', 'exists:evaluation_templates,id'], 'answers' => ['required', 'array']]);
        $rotation = Rotation::findOrFail($data['rotation_id']);
        abort_unless($rotation->supervisor_id === $request->user()->id || $rotation->internship->supervisor_id === $request->user()->id, 403);
        $template = EvaluationTemplate::findOrFail($data['template_id']);
        abort_unless($template->institution_id === $rotation->internship->hospital_id, 422);
        $criteria = collect($template->criteria)->keyBy('key');
        $score = 0;
        foreach ($data['answers'] as $key => $value) {
            abort_unless($criteria->has($key) && is_numeric($value) && $value >= 0 && $value <= $criteria[$key]['maximum'], 422, 'Score invalide.');
            $score += $value;
        }
        $evaluation = Evaluation::create(['rotation_id' => $rotation->id, 'template_id' => $template->id, 'student_id' => $rotation->internship->student_id, 'evaluator_id' => $request->user()->id, 'answers' => $data['answers'], 'score' => $score, 'status' => 'DRAFT']);

        return response()->json(['data' => $evaluation], 201);
    }

    public function submit(Request $request, Evaluation $evaluation): JsonResponse
    {
        abort_unless($evaluation->evaluator_id === $request->user()->id, 403);
        abort_unless($evaluation->status === 'DRAFT', 409);
        $evaluation->update(['status' => 'FINALIZED', 'submitted_at' => now()]);

        return response()->json(['data' => $evaluation]);
    }

    public function dispute(Request $request, Evaluation $evaluation): JsonResponse
    {
        abort_unless($evaluation->student()->where('user_id', $request->user()->id)->exists(), 403);
        abort_unless($evaluation->status === 'FINALIZED', 422);
        $data = $request->validate(['reason' => ['required', 'string', 'max:3000']]);

        return response()->json(['data' => EvaluationDispute::create(['evaluation_id' => $evaluation->id, 'opened_by' => $request->user()->id, 'reason' => $data['reason'], 'status' => 'OPEN'])], 201);
    }

    public function resolve(Request $request, EvaluationDispute $dispute): JsonResponse
    {
        $universityId = $dispute->evaluation->rotation->internship->admission->application->campaign->university_id;
        abort_unless(app(InstitutionAccess::class)->has($request->user(), $universityId, [InstitutionRole::AcademicManager->value]), 403);
        $data = $request->validate(['resolution' => ['required', 'string', 'max:3000']]);
        abort_unless($dispute->status === 'OPEN', 409);
        $dispute->update(['status' => 'RESOLVED', 'resolution' => $data['resolution'], 'resolved_by' => $request->user()->id, 'resolved_at' => now()]);

        return response()->json(['data' => $dispute]);
    }

    public function decision(Request $request, Internship $internship): JsonResponse
    {
        $universityId = $internship->admission->application->campaign->university_id;
        abort_unless(app(InstitutionAccess::class)->has($request->user(), $universityId, [InstitutionRole::AcademicManager->value]), 403);
        abort_if($internship->rotations()->whereDoesntHave('evaluations', fn ($query) => $query->where('status', 'FINALIZED'))->exists(), 422, 'Évaluations incomplètes.');
        $data = $request->validate(['decision' => ['required', 'in:VALIDATED,FAILED,REPEAT'], 'comments' => ['nullable', 'string', 'max:3000']]);
        $score = Evaluation::whereIn('rotation_id', $internship->rotations()->select('id'))->where('status', 'FINALIZED')->avg('score');
        $decision = AcademicDecision::create(['student_id' => $internship->student_id, 'internship_id' => $internship->id, 'decision' => $data['decision'], 'final_score' => $score, 'comments' => $data['comments'] ?? null, 'decided_by' => $request->user()->id, 'decided_at' => now()]);

        return response()->json(['data' => $decision], 201);
    }
}
