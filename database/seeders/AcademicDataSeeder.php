<?php

namespace Database\Seeders;

use App\Modules\Academic\Models\AcademicLevel;
use App\Modules\Academic\Models\AcademicProgram;
use App\Modules\Academic\Models\AcademicYear;
use App\Modules\Academic\Models\Promotion;
use App\Modules\Institution\Models\Institution;
use Illuminate\Database\Seeder;

class AcademicDataSeeder extends Seeder
{
    public function run(): void
    {
        $universities = Institution::query()->whereIn('registration_number', [
            'DEMO-UNIVERSITY-001', 'DEMO-UNIVERSITY-002',
        ])->get();

        $years = AcademicYear::query()->get()->keyBy('label');
        foreach ($universities as $university) {
            $programs = $university->registration_number === 'DEMO-UNIVERSITY-001'
                ? [
                    ['code' => 'MED', 'name' => 'Médecine', 'degree_type' => 'DOCTORAT', 'duration_years' => 7, 'levels' => ['L1', 'L2', 'L3', 'D1', 'D2', 'D3', 'D4']],
                    ['code' => 'NUR', 'name' => 'Sciences infirmières', 'degree_type' => 'LICENCE', 'duration_years' => 3, 'levels' => ['L1', 'L2', 'L3']],
                    ['code' => 'PH', 'name' => 'Santé publique', 'degree_type' => 'LICENCE', 'duration_years' => 3, 'levels' => ['L1', 'L2', 'L3']],
                ]
                : [
                    ['code' => 'MED', 'name' => 'Médecine', 'degree_type' => 'DOCTORAT', 'duration_years' => 7, 'levels' => ['L1', 'L2', 'L3', 'D1', 'D2', 'D3', 'D4']],
                    ['code' => 'PHA', 'name' => 'Pharmacie', 'degree_type' => 'DOCTORAT', 'duration_years' => 7, 'levels' => ['L1', 'L2', 'L3', 'D1', 'D2', 'D3', 'D4']],
                ];

            foreach ($programs as $programData) {
                $levelCodes = $programData['levels'];
                unset($programData['levels']);
                $program = AcademicProgram::query()->updateOrCreate(
                    ['university_id' => $university->id, 'code' => $programData['code']],
                    $programData + ['status' => 'ACTIVE'],
                );

                foreach ($levelCodes as $levelCode) {
                    $level = AcademicLevel::query()->where('code', $levelCode)->firstOrFail();
                    Promotion::query()->updateOrCreate(
                        ['program_id' => $program->id, 'level_id' => $level->id, 'academic_year_reference_id' => $years['2026-2027']->id],
                        ['academic_year_id' => null, 'name' => $program->code.' '.$level->code.' — 2026-2027', 'status' => 'ACTIVE'],
                    );
                }
            }
        }
    }

}
