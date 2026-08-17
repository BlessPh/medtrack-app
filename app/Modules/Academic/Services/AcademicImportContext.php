<?php

namespace App\Modules\Academic\Services;

use App\Modules\Academic\Models\AcademicYear;
use App\Modules\Academic\Models\Promotion;
use App\Modules\Institution\Models\Institution;
use Illuminate\Validation\ValidationException;

class AcademicImportContext
{
    public function resolve(string $universityPublicId, int $promotionId, int $academicYearId): array
    {
        $university = Institution::where('public_id', $universityPublicId)->where('type', 'UNIVERSITY')->first();
        if (! $university) {
            throw ValidationException::withMessages(['university_id' => 'L’université sélectionnée est introuvable.']);
        }

        $year = AcademicYear::whereKey($academicYearId)->first();
        if (! $year) {
            throw ValidationException::withMessages(['academic_year_id' => 'L’année académique sélectionnée n’appartient pas à cette université.']);
        }

        $promotion = Promotion::with('program')->whereKey($promotionId)->first();
        if (! $promotion) {
            throw ValidationException::withMessages(['promotion_id' => 'La promotion sélectionnée est introuvable. Rechargez la liste des promotions.']);
        }
        if ($promotion->academic_year_reference_id !== $year->id || $promotion->program->university_id !== $university->id) {
            throw ValidationException::withMessages(['promotion_id' => 'La promotion sélectionnée n’appartient pas à cette université et cette année académique.']);
        }

        return [$university, $promotion, $year];
    }
}
