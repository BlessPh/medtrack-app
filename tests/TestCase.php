<?php

namespace Tests;

use App\Modules\Auth\Models\User;
use App\Modules\Institution\Models\Institution;
use App\Shared\Services\InstitutionAccess;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Spatie\Permission\Models\Role;

abstract class TestCase extends BaseTestCase
{
    public function actingAs(Authenticatable $user, $guard = 'api'): static
    {
        return parent::actingAs($user, $guard);
    }

    protected function assignInstitutionRole(User $user, Institution $institution, string $role): void
    {
        setPermissionsTeamId(null);
        Role::findOrCreate($role, 'web');
        $institution->members()->syncWithoutDetaching([$user->id]);
        app(InstitutionAccess::class)->assign($user, $institution->id, $role);
    }

    protected function assignSuperAdmin(User $user): void
    {
        setPermissionsTeamId(null);
        Role::findOrCreate('SUPER_ADMIN', 'web');
        app(InstitutionAccess::class)->assignSuperAdmin($user);
    }
}
