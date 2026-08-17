<?php

namespace App\Modules\Academic\Policies;

use App\Modules\Auth\Models\User;
use App\Shared\Enums\InstitutionRole;
use App\Shared\Services\InstitutionAccess;
use App\Modules\Academic\Models\Student;

class AcademicPolicy
{
    public function view(User $user, int $institutionId): bool
    {
        return app(InstitutionAccess::class)->isSuperAdmin($user)
            || app(InstitutionAccess::class)->has($user, $institutionId, [
                InstitutionRole::Admin->value,
                InstitutionRole::AcademicManager->value,
            ]);
    }

    public function viewCatalog(User $user, int $institutionId): bool
    {
        return $this->view($user, $institutionId)
            || app(InstitutionAccess::class)->has($user, $institutionId, [
                InstitutionRole::FinanceOfficer->value,
            ]);
    }

    public function viewStudent(User $user, Student $student): bool
    {
        return $this->view($user, $student->university_id)
            || $student->user_id === $user->id;
    }

    public function manage(User $user, int $institutionId): bool
    {
        if (app(InstitutionAccess::class)->isSuperAdmin($user)) {
            return false;
        }

        return app(InstitutionAccess::class)->has($user, $institutionId, [InstitutionRole::Admin->value, InstitutionRole::AcademicManager->value]);
    }
}
