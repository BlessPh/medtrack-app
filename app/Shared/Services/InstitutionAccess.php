<?php

namespace App\Shared\Services;

use App\Modules\Auth\Models\User;
use App\Shared\Enums\InstitutionRole;
use Closure;
use Spatie\Permission\Models\Role;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InstitutionAccess
{
    public function has(User $user, int $institutionId, array $roles): bool
    {
        if ($this->isSuperAdmin($user)) {
            return true;
        }

        if (! $user->institutions()->whereKey($institutionId)->wherePivot('status', 'ACTIVE')->exists()) {
            return false;
        }

        return $this->inTeam($user, $institutionId, fn (): bool => $user->hasAnyRole($roles));
    }

    public function isSuperAdmin(User $user): bool
    {
        return $this->inTeam($user, 0, fn (): bool => $user->hasRole('SUPER_ADMIN'));
    }

    public function assign(User $user, int $institutionId, string $role): void
    {
        $this->ensureStudentRoleIsExclusive($user, [$role], $institutionId, false);
        $previousTeamId = getPermissionsTeamId();
        setPermissionsTeamId(null);
        try {
            Role::findOrCreate($role, 'web');
        } finally {
            setPermissionsTeamId($previousTeamId);
        }

        $this->inTeam($user, $institutionId, function () use ($user, $role): void {
            $user->assignRole($role);
        });
    }

    /** Replace only the roles held by the user in the selected institution. */
    public function sync(User $user, int $institutionId, array $roles): void
    {
        $this->ensureStudentRoleIsExclusive($user, $roles, $institutionId, true);
        $previousTeamId = getPermissionsTeamId();
        setPermissionsTeamId(null);
        try {
            foreach ($roles as $role) {
                Role::findOrCreate($role, 'web');
            }
        } finally {
            setPermissionsTeamId($previousTeamId);
        }

        $this->inTeam($user, $institutionId, fn () => $user->syncRoles($roles));
    }

    /** Revoke one responsibility without affecting the other assigned roles. */
    public function revoke(User $user, int $institutionId, string $role): void
    {
        $this->inTeam($user, $institutionId, fn () => $user->removeRole($role));
    }

    public function remove(User $user, int $institutionId): void
    {
        $this->inTeam($user, $institutionId, function () use ($user): void {
            $user->syncRoles([]);
        });
    }

    public function rolesFor(User $user, int $institutionId): array
    {
        return $this->inTeam($user, $institutionId, fn (): array => $user->getRoleNames()->values()->all());
    }

    public function assignSuperAdmin(User $user): void
    {
        $this->inTeam($user, 0, function () use ($user): void {
            $user->assignRole('SUPER_ADMIN');
        });
    }

    public function assignBootstrapRole(User $user, string $role): void
    {
        $this->ensureStudentRoleIsExclusive($user, [$role], 0, false);
        $this->inTeam($user, 0, function () use ($user, $role): void {
            $user->assignRole($role);
        });
    }

    public function hasBootstrapRole(User $user, string $role): bool
    {
        return $this->inTeam($user, 0, fn (): bool => $user->hasRole($role));
    }

    private function inTeam(User $user, int $institutionId, Closure $callback): mixed
    {
        $previousTeamId = getPermissionsTeamId();
        setPermissionsTeamId($institutionId);
        $user->unsetRelation('roles')->unsetRelation('permissions');

        try {
            return $callback();
        } finally {
            $user->unsetRelation('roles')->unsetRelation('permissions');
            setPermissionsTeamId($previousTeamId);
        }
    }

    private function ensureStudentRoleIsExclusive(User $user, array $roles, int $institutionId, bool $replacingTeam): void
    {
        if (in_array(InstitutionRole::Student->value, $roles, true) && count($roles) > 1) {
            throw ValidationException::withMessages(['roles' => 'Le rôle STUDENT est exclusif et ne peut être combiné avec aucun autre rôle.']);
        }
        $existing = DB::table('model_has_roles')->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('model_has_roles.model_type', $user->getMorphClass())->where('model_has_roles.model_id', $user->id)
            ->when($replacingTeam, fn ($query) => $query->where('model_has_roles.institution_id', '!=', $institutionId))
            ->pluck('roles.name')->all();
        $combined = array_unique([...$existing, ...$roles]);
        if (in_array(InstitutionRole::Student->value, $combined, true) && count($combined) > 1) {
            throw ValidationException::withMessages(['roles' => 'Un étudiant ne peut posséder que le rôle STUDENT.']);
        }
    }
}
