<?php

namespace App\Modules\Auth\Controllers;

use App\Modules\Academic\Models\AcademicYear;
use App\Modules\Institution\Models\Institution;
use Illuminate\Http\JsonResponse;

/** Référentiels publics minimaux nécessaires à l’inscription d’un étudiant. */
class StudentRegistrationReferenceController
{
    public function universities(): JsonResponse
    {
        $universities = Institution::query()
            ->where('type', 'UNIVERSITY')->where('status', 'ACTIVE')->orderBy('name')
            ->get(['public_id', 'name', 'acronym'])
            ->map(fn (Institution $institution): array => [
                'id' => $institution->public_id,
                'name' => $institution->name,
                'acronym' => $institution->acronym,
            ]);

        return response()->json(['data' => $universities]);
    }

    public function currentAcademicYear(): JsonResponse
    {
        $year = AcademicYear::query()->where('is_current', true)->where('status', 'CURRENT')
            ->first(['id', 'label', 'starts_on', 'ends_on']);
        return response()->json(['data' => $year]);
    }

}
