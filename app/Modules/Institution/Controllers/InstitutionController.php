<?php

namespace App\Modules\Institution\Controllers;

use App\Modules\Institution\Models\Institution;
use App\Modules\Institution\Requests\SaveInstitutionRequest;
use App\Modules\Institution\Resources\InstitutionResource;
use App\Shared\Services\InstitutionAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use App\Shared\Enums\InstitutionRole;
use App\Modules\Auth\Models\InstitutionAccountRequest;

class InstitutionController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->user()->can('viewAny', Institution::class) || abort(403);
        $query = Institution::query()->with(['addresses', 'logo'])->withCount(['units', 'members']);
        if (! app(InstitutionAccess::class)->isSuperAdmin($request->user())) {
            $query->where('status', 'ACTIVE');
            $query->whereHas('members', fn ($q) => $q->whereKey($request->user()));
        }

        $query->when($request->string('search')->trim()->value(), function ($query, string $search): void {
            $query->where(fn ($query) => $query->where('name', 'like', "%{$search}%")->orWhere('short_name', 'like', "%{$search}%")->orWhere('registration_number', 'like', "%{$search}%"));
        })->when($request->string('type')->trim()->value(), fn ($query, string $type) => $query->where('type', $type))
            ->when($request->string('status')->trim()->value(), fn ($query, string $status) => $query->where('status', $status));

        return InstitutionResource::collection($query->orderBy('name')->paginate(min($request->integer('per_page', 25), 100)));
    }

    public function store(SaveInstitutionRequest $request): InstitutionResource
    {
        $request->user()->can('create', Institution::class) || abort(403);

        $institution = DB::transaction(function () use ($request): Institution {
            $data = $request->validated();
            if (! app(InstitutionAccess::class)->isSuperAdmin($request->user())) {
                $approvedRequest = InstitutionAccountRequest::query()->where('created_user_id', $request->user()->id)
                    ->where('status', 'APPROVED')->lockForUpdate()->latest('reviewed_at')->firstOrFail();
                $data['type'] = $approvedRequest->institution_type;
            }
            $institution = Institution::create($data + ['status' => 'PENDING']);
            if (! app(InstitutionAccess::class)->isSuperAdmin($request->user())) {
                $institution->members()->attach($request->user()->id);
                app(InstitutionAccess::class)->assign($request->user(), $institution->id, InstitutionRole::Admin->value);
            }
            return $institution;
        });

        return new InstitutionResource($institution);
    }

    public function show(Request $request, Institution $institution): InstitutionResource
    {
        $request->user()->can('view', $institution) || abort(403);

        $institution->load(['units', 'addresses', 'contacts', 'logo'])->loadCount(['units', 'members']);
        $institution->setAttribute('students_count', DB::table('students')->where('university_id', $institution->id)->whereNull('deleted_at')->count());
        $institution->setAttribute('internships_count', DB::table('internships')->where('hospital_id', $institution->id)->count());

        return new InstitutionResource($institution->load('logo'));
    }

    /**
     * Return the operational overview of a hospital managed by the current user.
     * Every aggregate is scoped with the internal institution id after policy
     * authorization, so a client cannot inspect another hospital by changing the UUID.
     */
    public function dashboard(Request $request, Institution $institution): JsonResponse
    {
        $request->user()->can('view', $institution) || abort(403);
        abort_unless($institution->type === 'HOSPITAL', 422, 'Ce tableau de bord est réservé aux hôpitaux.');

        $supervisors = DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('model_has_roles.institution_id', $institution->id)
            ->where('model_has_roles.model_type', \App\Modules\Auth\Models\User::class)
            ->where('roles.name', InstitutionRole::Supervisor->value)
            ->distinct('model_has_roles.model_id')
            ->count('model_has_roles.model_id');

        $capacity = DB::table('capacity_pools')
            ->join('campaign_hospitals', 'campaign_hospitals.id', '=', 'capacity_pools.campaign_hospital_id')
            ->where('campaign_hospitals.hospital_id', $institution->id)
            ->selectRaw('COALESCE(SUM(capacity_pools.total_places), 0) AS total_places')
            ->selectRaw('COALESCE(SUM(capacity_pools.reserved_places), 0) AS reserved_places')
            ->first();

        $institution->load(['addresses', 'contacts', 'logo'])->loadCount(['units', 'members']);
        $missing = collect([
            'general_information' => filled($institution->registration_number) && filled($institution->description),
            'logo' => $institution->logo !== null,
            'address' => $institution->addresses->isNotEmpty(),
            'contact' => $institution->contacts->isNotEmpty(),
            'services' => $institution->units_count > 0,
            'members' => $institution->members_count > 1,
        ])->reject()->keys()->values();

        $notifications = $request->user()->notifications()->latest()->limit(50)->get()
            ->filter(fn ($notification) => ($notification->data['institution_id'] ?? null) === $institution->public_id)
            ->take(5)->values()->map(fn ($notification): array => [
                'id' => $notification->id,
                'title' => $notification->data['title'] ?? 'Notification',
                'message' => $notification->data['message'] ?? '',
                'severity' => $notification->data['severity'] ?? 'INFO',
                'url' => $notification->data['url'] ?? null,
                'read_at' => $notification->read_at,
                'created_at' => $notification->created_at,
            ]);

        return response()->json(['data' => [
            'institution' => new InstitutionResource($institution),
            'configuration' => [
                'completion' => (int) round((6 - $missing->count()) / 6 * 100),
                'missing' => $missing,
            ],
            'statistics' => [
                'services' => $institution->units_count,
                'members' => $institution->members_count,
                'supervisors' => $supervisors,
                'active_internships' => DB::table('internships')->where('hospital_id', $institution->id)->where('status', 'ACTIVE')->count(),
                'pending_applications' => DB::table('applications')->where('preferred_hospital_id', $institution->id)->where('status', 'SUBMITTED')->count(),
                'pending_d4_requests' => DB::table('campaign_hospitals')->where('hospital_id', $institution->id)->where('request_status', 'PENDING')->count(),
                'total_capacity' => (int) ($capacity->total_places ?? 0),
                'reserved_capacity' => (int) ($capacity->reserved_places ?? 0),
                'available_capacity' => max(0, (int) ($capacity->total_places ?? 0) - (int) ($capacity->reserved_places ?? 0)),
            ],
            'recent_notifications' => $notifications,
        ]]);
    }

    public function update(SaveInstitutionRequest $request, Institution $institution): InstitutionResource
    {
        $request->user()->can('update', $institution) || abort(403);
        $institution->update($request->validated());

        return new InstitutionResource($institution);
    }

    public function status(Request $request, Institution $institution): JsonResponse
    {
        abort_unless(app(InstitutionAccess::class)->isSuperAdmin($request->user()), 403);
        $data = $request->validate(['status' => ['required', 'in:ACTIVE,SUSPENDED,DISABLED']]);
        $institution->update($data + ['verified_at' => $data['status'] === 'ACTIVE' ? now() : $institution->verified_at]);

        return response()->json(['data' => new InstitutionResource($institution)]);
    }
}
