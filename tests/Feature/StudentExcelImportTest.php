<?php

namespace Tests\Feature;

use App\Modules\Academic\Models\AcademicLevel;
use App\Modules\Academic\Models\AcademicProgram;
use App\Modules\Academic\Models\AcademicYear;
use App\Modules\Academic\Models\Promotion;
use App\Modules\Auth\Models\User;
use App\Modules\Institution\Models\Institution;
use App\Shared\Enums\InstitutionRole;
use Database\Seeders\AcademicLevelSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Tests\TestCase;

class StudentExcelImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_university_previews_confirms_and_student_creates_account(): void
    {
        [$manager, $university, $year, $promotion] = $this->context();
        $file = $this->spreadsheet([
            ['Nom', 'Post-nom', 'Prénom', 'Sexe', 'Date de naissance', 'Matricule', 'Email'],
            ['Masiala', 'Phambu', 'Bruno', 'M', '2000-01-31', 'med-001', 'bruno@example.test'],
            ['Invalide', 'Test', 'Ligne', 'F', '2001-02-28', '', 'incorrect'],
        ]);

        $preview = $this->actingAs($manager)->post('/api/v1/academic/student-imports/preview', ['university_id' => $university->public_id, 'promotion_id' => $promotion->id, 'academic_year_id' => $year->id, 'file' => $file], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('data.summary.total', 2)->assertJsonPath('data.summary.valid', 1)->assertJsonPath('data.rows.0.student.student_number', 'MED-001');
        $selected = [$preview->json('data.rows.0.student')];
        $this->postJson('/api/v1/academic/student-imports/confirm', ['university_id' => $university->public_id, 'promotion_id' => $promotion->id, 'academic_year_id' => $year->id, 'students' => $selected])
            ->assertCreated()->assertJsonPath('data.imported', 1);
        $this->assertDatabaseHas('students', ['university_id' => $university->id, 'student_number' => 'MED-001']);
        $this->assertDatabaseHas('enrollments', ['promotion_id' => $promotion->id, 'status' => 'ACTIVE']);

        $claim = $this->postJson('/api/v1/auth/student-registration/check', ['university_id' => $university->public_id, 'promotion_id' => $promotion->id, 'academic_year_id' => $year->id, 'student_number' => 'med-001'])->assertOk()->json('data.registration_token');
        $payload = ['registration_token' => $claim, 'email' => 'student@example.test', 'password' => 'very-secure-password', 'password_confirmation' => 'very-secure-password', 'city' => 'Kinshasa'];
        $this->postJson('/api/v1/auth/student-registration', $payload)->assertCreated()->assertJsonPath('data.email', 'student@example.test');
        $this->assertDatabaseHas('users', ['email' => 'student@example.test']);
        $this->assertDatabaseHas('institution_memberships', ['institution_id' => $university->id]);
        $studentUser = User::where('email', 'student@example.test')->firstOrFail();
        $this->assertTrue(app(\App\Shared\Services\InstitutionAccess::class)->has($studentUser, $university->id, [InstitutionRole::Student->value]));
        $this->postJson('/api/v1/auth/student-registration', $payload)->assertStatus(422);
    }

    public function test_confirmation_rejects_duplicate_matricules(): void
    {
        [$manager, $university, $year, $promotion] = $this->context();
        $student = ['student_number' => 'DUP-001', 'last_name' => 'Nom', 'middle_name' => 'Postnom', 'first_name' => 'Prénom', 'gender' => 'MALE', 'birth_date' => '2000-01-01'];
        $this->actingAs($manager)->postJson('/api/v1/academic/student-imports/confirm', ['university_id' => $university->public_id, 'promotion_id' => $promotion->id, 'academic_year_id' => $year->id, 'students' => [$student, $student]])->assertUnprocessable();
        $this->assertDatabaseCount('students', 0);
    }

    public function test_registration_rejects_a_wrong_academic_context(): void
    {
        [, $university, $year, $promotion] = $this->context();
        $other = Institution::factory()->create(['type' => 'UNIVERSITY']);
        $this->postJson('/api/v1/auth/student-registration/check', ['university_id' => $other->public_id, 'promotion_id' => $promotion->id, 'academic_year_id' => $year->id, 'student_number' => 'UNKNOWN'])
            ->assertUnprocessable()->assertJsonValidationErrors('promotion_id');
    }

    private function context(): array
    {
        $this->seed(AcademicLevelSeeder::class);
        $manager = User::factory()->create();
        $university = Institution::factory()->create(['type' => 'UNIVERSITY']);
        $this->assignInstitutionRole($manager, $university, InstitutionRole::AcademicManager->value);
        $year = AcademicYear::create(['label' => '2026-2027', 'starts_on' => '2026-09-01', 'ends_on' => '2027-08-31', 'status' => 'CURRENT', 'is_current' => true]);
        $program = AcademicProgram::create(['university_id' => $university->id, 'code' => 'MED', 'name' => 'Médecine', 'status' => 'ACTIVE']);
        $promotion = Promotion::create(['program_id' => $program->id, 'level_id' => AcademicLevel::first()->id, 'academic_year_id' => null, 'academic_year_reference_id' => $year->id, 'name' => 'L1', 'status' => 'ACTIVE']);

        return [$manager, $university, $year, $promotion];
    }

    private function spreadsheet(array $rows): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'medtrack-students-');
        $writer = new Writer;
        $writer->openToFile($path);
        foreach ($rows as $row) {
            $writer->addRow(Row::fromValues($row));
        }
        $writer->close();

        return new UploadedFile($path, 'students.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }
}
