<?php

namespace App\Modules\Institution\Policies;

use App\Modules\Auth\Models\User;
use App\Modules\Institution\Models\Institution;
use App\Shared\Enums\InstitutionRole;
use App\Shared\Services\InstitutionAccess;

class InstitutionPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Institution $institution): bool
    {
        return app(InstitutionAccess::class)->isSuperAdmin($user) || $user->institutions()->whereKey($institution)->exists();
    }

    public function create(User $user): bool
    {
        $access = app(InstitutionAccess::class);
        return $access->isSuperAdmin($user) || ($access->hasBootstrapRole($user, InstitutionRole::Admin->value) && ! $user->institutions()->exists());
    }

    public function update(User $user, Institution $institution): bool
    {
        return app(InstitutionAccess::class)->has($user, $institution->id, [InstitutionRole::Admin->value]);
    }

    public function manageMembers(User $user, Institution $institution): bool
    {
        return $this->update($user, $institution);
    }
}
