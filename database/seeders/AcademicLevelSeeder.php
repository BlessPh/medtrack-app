<?php

namespace Database\Seeders;

use App\Modules\Academic\Models\AcademicLevel;
use Illuminate\Database\Seeder;

class AcademicLevelSeeder extends Seeder
{
    public function run(): void
    {
        $levels = [
            ['code' => 'L1', 'label' => 'Licence 1', 'cycle' => 'LICENCE', 'display_order' => 10],
            ['code' => 'L2', 'label' => 'Licence 2', 'cycle' => 'LICENCE', 'display_order' => 20],
            ['code' => 'L3', 'label' => 'Licence 3', 'cycle' => 'LICENCE', 'display_order' => 30],
            ['code' => 'D1', 'label' => 'Doctorat 1', 'cycle' => 'DOCTORAT', 'display_order' => 40],
            ['code' => 'D2', 'label' => 'Doctorat 2', 'cycle' => 'DOCTORAT', 'display_order' => 50],
            ['code' => 'D3', 'label' => 'Doctorat 3', 'cycle' => 'DOCTORAT', 'display_order' => 60],
            ['code' => 'D4', 'label' => 'Doctorat 4', 'cycle' => 'DOCTORAT', 'display_order' => 70],
        ];

        foreach ($levels as $level) {
            AcademicLevel::query()->updateOrCreate(['code' => $level['code']], $level + ['status' => 'ACTIVE']);
        }
    }
}
