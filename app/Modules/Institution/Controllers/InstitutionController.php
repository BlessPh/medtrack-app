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
