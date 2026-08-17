<?php

namespace App\Shared\Services;

use App\Modules\Auth\Models\User;
use Closure;
use Spatie\Permission\Models\Role;

class InstitutionAccess
{
    public function has(User $user, int $institutionId, array $roles): bool
    {
        if ($this->isSuperAdmin($user)) {
            return true;
        }

        if (! $user->institutions()->whereKey($institutionId)->exists()) {
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
        $previousTeamId = getPermissionsTeamId();
        setPermissionsTeamId(null);
        try {
            Role::findOrCreate($role, 'web');
        } finally {
            setPermissionsTeamId($previousTeamId);
        }

        $this->inTeam($user, $institutionId, function () use ($user, $role): void {
            $user->syncRoles([$role]);
        });
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
}
