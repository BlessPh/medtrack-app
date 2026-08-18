<?php

namespace App\Modules\Academic\Controllers;

use App\Modules\Academic\Models\AcademicYear;
use App\Modules\Academic\Models\Campaign;
use App\Modules\Academic\Models\Promotion;
use App\Modules\Academic\Models\Student;
use App\Modules\Academic\Policies\AcademicPolicy;
use App\Modules\Academic\Services\EligibilityService;
use App\Modules\Academic\Services\RichTextSanitizer;
use App\Modules\Institution\Models\Institution;
use App\Shared\Services\InstitutionAccess;
use App\Modules\Notification\Notifications\InstitutionNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\Rule;

class CampaignManagementController
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['university_id' => ['required', 'uuid'], 'status' => ['nullable', Rule::in(['DRAFT', 'OPEN', 'CLOSED', 'CANCELLED'])], 'academic_year_id' => ['nullable', 'integer']]);
        $university = $this->university($data['university_id']);
        $this->view($request, $university);
        $query = Campaign::query()->where('university_id', $university->id)->with($this->relations())->withCount(['promotions', 'hospitals']);
        if (isset($data['status'])) $query->where('status', $data['status']);
        if (isset($data['academic_year_id'])) $query->where('academic_year_reference_id', $data['academic_year_id']);
        return response()->json(['data' => $query->latest('starts_at')->get()]);
    }

    public function show(Request $request, Campaign $campaign): JsonResponse
    {
        $this->view($request, Institution::findOrFail($campaign->university_id));
        $campaign->load($this->relations())->loadCount(['promotions', 'hospitals']);
        $students = Student::query()->where('university_id', $campaign->university_id)->where('status', 'ACTIVE')
            ->with(['enrollments' => fn ($query) => $query->where('status', 'ACTIVE')->with('promotion')])->orderBy('last_name')->get();
        $promotionIds = $campaign->promotions->pluck('id');
        [$eligible, $ineligible] = $students->partition(fn (Student $student) => $student->enrollments->contains(fn ($enrollment) => $promotionIds->contains($enrollment->promotion_id)));
        $map = fn ($items) => $items->map(fn (Student $student) => ['id' => $student->public_id, 'student_number' => $student->student_number, 'name' => trim(collect([$student->last_name, $student->middle_name, $student->first_name])->filter()->implode(' ')), 'promotion' => $student->enrollments->first()?->promotion?->name])->values();
        return response()->json(['data' => [...$campaign->toArray(), 'statistics' => ['eligible' => $eligible->count(), 'ineligible' => $ineligible->count(), 'applications' => DB::table('applications')->where('campaign_id', $campaign->id)->count()], 'eligible_students' => $map($eligible), 'ineligible_students' => $map($ineligible)]]);
    }

    public function store(Request $request): JsonResponse
    {
        [$data, $university, $year] = $this->validated($request);
        abort_unless(app(AcademicPolicy::class)->manage($request->user(), $university->id), 403);
        $campaign = DB::transaction(function () use ($data, $university, $year): Campaign {
            $campaign = Campaign::create(['university_id' => $university->id, 'academic_year_id' => null, 'academic_year_reference_id' => $year->id, 'name' => $data['name'], 'regime' => $data['regime'] ?? null, 'strategy' => $data['strategy'], 'instructions' => $data['instructions'] ?? null, 'starts_at' => $data['starts_at'], 'ends_at' => $data['ends_at'], 'status' => 'DRAFT']);
            $campaign->promotions()->sync(array_unique($data['promotion_ids']));
            $campaign->hospitals()->createMany($this->hospitalRows($data['hospital_ids'] ?? [], $data['strategy']));
            return $campaign;
        });
        return response()->json(['data' => $campaign->load($this->relations())], 201);
    }

    public function update(Request $request, Campaign $campaign): JsonResponse
    {
        abort_unless($campaign->status === 'DRAFT', 409, 'Seule une campagne en brouillon peut être modifiée.');
        [$data, $university, $year] = $this->validated($request, $campaign);
        abort_unless($university->id === $campaign->university_id && app(AcademicPolicy::class)->manage($request->user(), $university->id), 403);
        DB::transaction(function () use ($campaign, $data, $year): void {
            $campaign->update(['academic_year_reference_id' => $year->id, 'name' => $data['name'], 'regime' => $data['regime'] ?? null, 'strategy' => $data['strategy'], 'instructions' => $data['instructions'] ?? null, 'starts_at' => $data['starts_at'], 'ends_at' => $data['ends_at']]);
            $campaign->promotions()->sync(array_unique($data['promotion_ids']));
            $campaign->hospitals()->delete();
            $campaign->hospitals()->createMany($this->hospitalRows($data['hospital_ids'] ?? [], $data['strategy']));
        });
        return response()->json(['data' => $campaign->fresh()->load($this->relations())]);
    }

    public function status(Request $request, Campaign $campaign): JsonResponse
    {
        abort_unless(app(AcademicPolicy::class)->manage($request->user(), $campaign->university_id), 403);
        $data = $request->validate(['status' => ['required', Rule::in(['OPEN', 'CLOSED', 'CANCELLED'])]]);
        $allowed = ['DRAFT' => ['OPEN', 'CANCELLED'], 'OPEN' => ['CLOSED', 'CANCELLED']];
        abort_unless(in_array($data['status'], $allowed[$campaign->status] ?? [], true), 409, 'Transition de campagne invalide.');
        $campaign->update($data);
        if ($data['status'] === 'OPEN') $this->notifyOpening($campaign->fresh()->load(['promotions', 'hospitals.hospital']));
        return response()->json(['data' => $campaign->fresh()]);
    }

    public function eligibility(Request $request, Campaign $campaign, Student $student, EligibilityService $service): JsonResponse
    {
        abort_unless($student->university_id === $campaign->university_id, 404);
        abort_unless(app(InstitutionAccess::class)->isSuperAdmin($request->user()) || $student->user_id === $request->user()->id || app(AcademicPolicy::class)->view($request->user(), $campaign->university_id), 403);
        return response()->json(['data' => ['eligible' => $service->isEligible($student, $campaign)]]);
    }

    private function validated(Request $request, ?Campaign $campaign = null): array
    {
        $data = $request->validate(['university_id' => ['required', 'uuid'], 'academic_year_id' => ['required', 'integer', 'exists:academic_year_references,id'], 'name' => ['required', 'string', 'max:200'], 'regime' => ['nullable', 'string', 'max:40'], 'strategy' => ['required', Rule::in(['STANDARD', 'D4_RESERVATION'])], 'instructions' => ['nullable', 'string', 'max:10000'], 'starts_at' => ['required', 'date'], 'ends_at' => ['required', 'date', 'after:starts_at'], 'promotion_ids' => ['required', 'array', 'min:1'], 'promotion_ids.*' => ['integer', 'distinct', 'exists:promotions,id'], 'hospital_ids' => ['nullable', 'array', 'max:10'], 'hospital_ids.*' => ['uuid', 'distinct', Rule::exists('institutions', 'public_id')->where('type', 'HOSPITAL')]]);
        // Les consignes sont du HTML enrichi provenant d’un contentEditable :
        // elles doivent être nettoyées avant toute écriture en base.
        $data['instructions'] = app(RichTextSanitizer::class)->sanitize($data['instructions'] ?? null);
        $university = $this->university($data['university_id']);
        $valid = Promotion::query()->whereIn('id', $data['promotion_ids'])->where('academic_year_reference_id', $data['academic_year_id'])->whereHas('program', fn ($query) => $query->where('university_id', $university->id))->count();
        abort_unless($valid === count(array_unique($data['promotion_ids'])), 422, 'Les promotions doivent appartenir à cette université et à l’année sélectionnée.');
        $levels = Promotion::query()->whereIn('id', $data['promotion_ids'])->with('level')->get()->pluck('level.code')->map(fn ($code) => mb_strtoupper((string) $code));
        if ($data['strategy'] === 'D4_RESERVATION') {
            abort_unless($levels->isNotEmpty() && $levels->every(fn ($code) => $code === 'D4'), 422, 'Une campagne D4 ne peut concerner que des promotions de niveau D4.');
            abort_unless(count($data['hospital_ids'] ?? []) >= 1, 422, 'Sélectionnez au moins un hôpital pour une campagne D4.');
        } else abort_if($levels->contains('D4'), 422, 'Les promotions D4 nécessitent une campagne avec réservation hospitalière.');
        return [$data, $university, AcademicYear::findOrFail($data['academic_year_id'])];
    }

    private function university(string $publicId): Institution { return Institution::where('public_id', $publicId)->where('type', 'UNIVERSITY')->firstOrFail(); }
    private function view(Request $request, Institution $university): void { abort_unless(app(AcademicPolicy::class)->view($request->user(), $university->id), 403); }
    private function relations(): array { return ['academicYear', 'promotions.program', 'promotions.level', 'hospitals.hospital', 'media']; }
    private function hospitalRows(array $ids, string $strategy): array { return Institution::query()->whereIn('public_id', array_unique($ids))->where('type', 'HOSPITAL')->pluck('id')->map(fn ($id) => ['hospital_id' => $id, 'capacity' => 0, 'status' => 'ACTIVE', 'request_status' => $strategy === 'D4_RESERVATION' ? 'PENDING' : 'ACCEPTED'])->all(); }
    private function notifyOpening(Campaign $campaign): void
    {
        $university = Institution::findOrFail($campaign->university_id);
        $users = Student::query()->whereNotNull('user_id')->whereHas('enrollments', fn ($query) => $query->where('status', 'ACTIVE')->whereIn('promotion_id', $campaign->promotions->pluck('id')))->with('user')->get()->pluck('user')->filter();
        Notification::send($users, new InstitutionNotification($university->public_id, $university->name, 'Nouvelle campagne de stage', $campaign->name.' est désormais ouverte.', 'CAMPAIGN', 'INFO', '/student/campaigns/'.$campaign->public_id));
        if ($campaign->strategy === 'D4_RESERVATION') foreach ($campaign->hospitals as $request) {
            $request->update(['requested_at' => now()]);
            Notification::send($request->hospital->members()->get(), new InstitutionNotification($request->hospital->public_id, $request->hospital->name, 'Demande de réservation D4', $university->name.' souhaite réserver des places de stage de fin de cycle.', 'CAMPAIGN_REQUEST', 'INFO', '/hospital/campaign-requests/'.$request->public_id));
        }
    }
}
