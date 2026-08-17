<?php

namespace App\Modules\Media\Policies;

use App\Modules\Academic\Models\Student;
use App\Modules\Auth\Models\User;
use App\Modules\Internship\Models\Internship;
use App\Modules\Media\Models\Document;
use App\Shared\Services\InstitutionAccess;

class DocumentPolicy
{
    public function view(User $user, Document $document): bool
    {
        if (app(InstitutionAccess::class)->isSuperAdmin($user) || $document->uploaded_by === $user->id) {
            return true;
        }
        $owner = $document->documentable;
        if ($owner instanceof Student) {
            return $owner->user_id === $user->id || $user->institutions()->whereKey($owner->university_id)->exists();
        }
        if ($owner instanceof Internship) {
            return Student::whereKey($owner->student_id)->where('user_id', $user->id)->exists() || $user->institutions()->whereKey($owner->hospital_id)->exists();
        }

        return false;
    }

    public function delete(User $user, Document $document): bool
    {
        return $document->uploaded_by === $user->id || app(InstitutionAccess::class)->isSuperAdmin($user);
    }
}
