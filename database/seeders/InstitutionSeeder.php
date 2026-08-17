<?php

namespace Database\Seeders;

use App\Modules\Institution\Models\Institution;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class InstitutionSeeder extends Seeder
{
    public function run(): void
    {
        $institutions = [
            ['registration_number' => 'DEMO-UNIVERSITY-001', 'type' => 'UNIVERSITY', 'name' => 'Université MedTrack Démo', 'short_name' => 'UMD'],
            ['registration_number' => 'DEMO-UNIVERSITY-002', 'type' => 'UNIVERSITY', 'name' => 'Université de Kinshasa Démo', 'short_name' => 'UKD'],
            ['registration_number' => 'DEMO-HOSPITAL-001', 'type' => 'HOSPITAL', 'name' => 'Hôpital MedTrack Démo', 'short_name' => 'HMD'],
            ['registration_number' => 'DEMO-HOSPITAL-002', 'type' => 'HOSPITAL', 'name' => 'Hôpital Général Démo', 'short_name' => 'HGD'],
        ];

        foreach ($institutions as $data) {
            $institution = Institution::query()->firstOrCreate(
                ['registration_number' => $data['registration_number']],
                ['public_id' => (string) Str::uuid()] + $data,
            );
            $institution->update($data + ['status' => 'ACTIVE', 'verified_at' => $institution->verified_at ?? now()]);
        }
    }
}
