<?php

namespace Database\Seeders;

use App\Modules\Academic\Models\AcademicYear;
use Illuminate\Database\Seeder;

class AcademicYearSeeder extends Seeder
{
    public function run(): void
    {
        $definitions = [
            ['label' => '2025-2026', 'starts_on' => '2025-09-01', 'ends_on' => '2026-08-31', 'status' => 'PAST', 'is_current' => false],
            ['label' => '2026-2027', 'starts_on' => '2026-09-01', 'ends_on' => '2027-08-31', 'status' => 'CURRENT', 'is_current' => true],
            ['label' => '2027-2028', 'starts_on' => '2027-09-01', 'ends_on' => '2028-08-31', 'status' => 'UPCOMING', 'is_current' => false],
        ];
        foreach ($definitions as $definition) {
            AcademicYear::query()->updateOrCreate(['label' => $definition['label']], $definition);
        }
    }
}
