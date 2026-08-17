<?php

namespace App\Modules\Auth\Controllers;

use App\Modules\Auth\Models\User;
use App\Modules\Auth\Resources\UserResource;
use App\Shared\Enums\UserStatus;
use App\Shared\Services\InstitutionAccess;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class UserAdminController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        abort_unless(app(InstitutionAccess::class)->isSuperAdmin($request->user()), 403);

        $users = User::query()
            ->with('profile')
            ->when($request->string('search')->trim()->value(), function ($query, string $search): void {
                $query->where(function ($query) use ($search): void {
                    $query->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%");
                });
            })
            ->when($request->string('status')->trim()->value(), fn ($query, string $status) => $query->where('status', $status))
            ->when($request->string('role')->trim()->value(), fn ($query, string $role) => $query->role($role))
            ->when($request->string('institution_id')->trim()->value(), fn ($query, string $institutionId) => $query->whereHas('institutions', fn ($query) => $query->where('institutions.public_id', $institutionId)))
            ->latest()
            ->paginate(min($request->integer('per_page', 25), 100));

        return UserResource::collection($users);
    }

    public function show(Request $request, User $user): UserResource
    {
        abort_unless(app(InstitutionAccess::class)->isSuperAdmin($request->user()), 403);

        return new UserResource($user->load('profile'));
    }

    public function avatar(Request $request, User $user): StreamedResponse
    {
        abort_unless(app(InstitutionAccess::class)->isSuperAdmin($request->user()), 403);
        $path = $user->profile()->value('avatar_url');
        abort_unless($path && Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path, null, [
            'Cache-Control' => 'private, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function status(Request $request, User $user): UserResource
    {
        abort_unless(app(InstitutionAccess::class)->isSuperAdmin($request->user()), 403);
        $data = $request->validate(['status' => ['required', Rule::enum(UserStatus::class)]]);
        abort_if($request->user()->is($user) && $data['status'] !== UserStatus::Active->value, 409, 'Vous ne pouvez pas désactiver votre propre compte.');
        $user->update($data);

        return new UserResource($user->fresh()->load('profile'));
    }
}
