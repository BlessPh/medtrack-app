<?php

namespace Database\Seeders;

use App\Modules\Auth\Models\User;
use App\Modules\Institution\Models\Institution;
use App\Shared\Enums\InstitutionRole;
use App\Shared\Enums\UserStatus;
use App\Shared\Services\InstitutionAccess;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        $access = app(InstitutionAccess::class);
        $university = Institution::query()->where('registration_number', 'DEMO-UNIVERSITY-001')->firstOrFail();
        $hospital = Institution::query()->where('registration_number', 'DEMO-HOSPITAL-001')->firstOrFail();

        $accounts = [
            ['name' => 'Super Administrateur', 'email' => 'superadmin@medtrack.test', 'role' => 'SUPER_ADMIN', 'institution' => null],
            ['name' => 'Administrateur Université', 'email' => 'university.admin@medtrack.test', 'role' => InstitutionRole::Admin->value, 'institution' => $university],
            ['name' => 'Responsable Académique', 'email' => 'academic.manager@medtrack.test', 'role' => InstitutionRole::AcademicManager->value, 'institution' => $university],
            ['name' => 'Responsable Financier Université', 'email' => 'university.finance@medtrack.test', 'role' => InstitutionRole::FinanceOfficer->value, 'institution' => $university],
            ['name' => 'Étudiant Démonstration', 'email' => 'student@medtrack.test', 'role' => InstitutionRole::Student->value, 'institution' => $university],
            ['name' => 'Membre Université', 'email' => 'university.member@medtrack.test', 'role' => InstitutionRole::Member->value, 'institution' => $university],
            ['name' => 'Administrateur Hôpital', 'email' => 'hospital.admin@medtrack.test', 'role' => InstitutionRole::Admin->value, 'institution' => $hospital],
            ['name' => 'Responsable Hôpital', 'email' => 'hospital.manager@medtrack.test', 'role' => InstitutionRole::HospitalManager->value, 'institution' => $hospital],
            ['name' => 'Superviseur de Stage', 'email' => 'supervisor@medtrack.test', 'role' => InstitutionRole::Supervisor->value, 'institution' => $hospital],
            ['name' => 'Responsable Financier Hôpital', 'email' => 'hospital.finance@medtrack.test', 'role' => InstitutionRole::FinanceOfficer->value, 'institution' => $hospital],
        ];

        foreach ($accounts as $account) {
            $user = User::query()->firstOrCreate(
                ['email' => $account['email']],
                [
                    'public_id' => (string) Str::uuid(),
                    'name' => $account['name'],
                    'password' => Hash::make('Password123!'),
                    'status' => UserStatus::Active,
                    'email_verified_at' => now(),
                ],
            );

            if ($account['institution'] instanceof Institution) {
                $account['institution']->members()->syncWithoutDetaching([$user->id]);
                $access->assign($user, $account['institution']->id, $account['role']);
            } else {
                $access->assignSuperAdmin($user);
            }
        }
    }

}
