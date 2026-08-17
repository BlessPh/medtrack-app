<?php

namespace App\Modules\Auth\Controllers;

use App\Modules\Auth\Models\InstitutionAccountRequest;
use App\Modules\Auth\Models\User;
use App\Modules\Auth\Notifications\InstitutionAccountApprovedNotification;
use App\Modules\Auth\Requests\StoreInstitutionAccountRequest;
use App\Modules\Auth\Resources\InstitutionAccountRequestResource;
use App\Shared\Enums\InstitutionRole;
use App\Shared\Enums\UserStatus;
use App\Shared\Services\InstitutionAccess;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class InstitutionAccountRequestController
{
    public function store(StoreInstitutionAccountRequest $request): InstitutionAccountRequestResource
    {
        $data = $request->validated();
        $email = Str::lower($data['email']);
        $duplicate = InstitutionAccountRequest::query()->where('email', $email)->whereIn('status', ['PENDING', 'APPROVED'])->exists();
        throw_if($duplicate, ValidationException::withMessages(['email' => ['Une demande active existe déjà pour cette adresse.']]));

        $accountRequest = InstitutionAccountRequest::create([
            ...$data,
            'email' => $email,
            'reference' => 'MTK-'.now()->format('ymd').'-'.Str::upper(Str::random(6)),
            'password_hash' => Hash::make($data['password']),
            'status' => 'PENDING',
        ]);

        return new InstitutionAccountRequestResource($accountRequest);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorizeSuperAdmin($request);
        $query = InstitutionAccountRequest::query()->with(['reviewer', 'createdUser']);
        $query->when($request->string('search')->trim()->value(), fn ($query, string $search) => $query->where(fn ($query) => $query->where('reference', 'like', "%{$search}%")->orWhere('institution_name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%")))
            ->when($request->string('status')->trim()->value(), fn ($query, string $status) => $query->where('status', $status))
            ->when($request->string('type')->trim()->value(), fn ($query, string $type) => $query->where('institution_type', $type));

        return InstitutionAccountRequestResource::collection($query->latest()->paginate(min($request->integer('per_page', 25), 100)));
    }

    public function show(Request $request, InstitutionAccountRequest $institutionAccountRequest): InstitutionAccountRequestResource
    {
        $this->authorizeSuperAdmin($request);
        return new InstitutionAccountRequestResource($institutionAccountRequest->load(['reviewer', 'createdUser']));
    }

    public function approve(Request $request, InstitutionAccountRequest $institutionAccountRequest): InstitutionAccountRequestResource
    {
        $this->authorizeSuperAdmin($request);
        $accountRequest = DB::transaction(function () use ($request, $institutionAccountRequest): InstitutionAccountRequest {
            $accountRequest = InstitutionAccountRequest::query()->lockForUpdate()->findOrFail($institutionAccountRequest->id);
            abort_if($accountRequest->status === 'REJECTED', 409, 'Une demande refusée ne peut pas être approuvée.');
            if ($accountRequest->status === 'APPROVED') return $accountRequest;
            throw_if(User::query()->where('email', $accountRequest->email)->exists(), ValidationException::withMessages(['email' => ['Un compte utilise déjà cette adresse.']]));

            $user = User::create([
                'name' => trim(implode(' ', array_filter([$accountRequest->first_name, $accountRequest->middle_name, $accountRequest->last_name]))),
                'email' => $accountRequest->email,
                'phone' => $accountRequest->phone,
                'password' => $accountRequest->password_hash,
                'status' => UserStatus::Active,
            ]);
            $user->profile()->create(['first_name' => $accountRequest->first_name, 'last_name' => $accountRequest->last_name, 'gender' => $accountRequest->gender, 'metadata' => ['middle_name' => $accountRequest->middle_name, 'job_title' => $accountRequest->job_title]]);
            app(InstitutionAccess::class)->assignBootstrapRole($user, InstitutionRole::Admin->value);
            $accountRequest->update(['status' => 'APPROVED', 'reviewed_by' => $request->user()->id, 'created_user_id' => $user->id, 'reviewed_at' => now(), 'rejection_reason' => null]);
            $user->notify(new InstitutionAccountApprovedNotification($accountRequest));
            return $accountRequest;
        });

        return new InstitutionAccountRequestResource($accountRequest->load(['reviewer', 'createdUser']));
    }

    public function reject(Request $request, InstitutionAccountRequest $institutionAccountRequest): InstitutionAccountRequestResource
    {
        $this->authorizeSuperAdmin($request);
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        abort_if($institutionAccountRequest->status === 'APPROVED', 409, 'Une demande approuvée ne peut pas être refusée.');
        $institutionAccountRequest->update(['status' => 'REJECTED', 'rejection_reason' => $data['reason'], 'reviewed_by' => $request->user()->id, 'reviewed_at' => now()]);
        return new InstitutionAccountRequestResource($institutionAccountRequest->load(['reviewer', 'createdUser']));
    }

    private function authorizeSuperAdmin(Request $request): void
    {
        abort_unless(app(InstitutionAccess::class)->isSuperAdmin($request->user()), 403);
    }
}
