<?php

namespace Tests\Integration;

use App\Modules\Academic\Models\AcademicLevel;
use App\Modules\Academic\Models\AcademicProgram;
use App\Modules\Academic\Models\AcademicYear;
use App\Modules\Academic\Models\Promotion;
use App\Modules\Academic\Models\Student;
use App\Modules\Auth\Models\User;
use App\Modules\Institution\Models\Institution;
use App\Shared\Enums\InstitutionRole;
use Database\Seeders\AcademicLevelSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Tests\TestCase;

class StudentImportRegistrationIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_preview_requires_authentication_and_academic_role_in_the_selected_university(): void
    {
        [$manager, $university, $year, $promotion] = $this->context();
        $payload = ['university_id' => $university->public_id, 'promotion_id' => $promotion->id, 'academic_year_id' => $year->id];
        $this->post('/api/v1/academic/student-imports/preview', $payload + ['file' => $this->validFile()], ['Accept' => 'application/json'])->assertUnauthorized();
        $this->actingAs(User::factory()->create())->post('/api/v1/academic/student-imports/preview', $payload + ['file' => $this->validFile()], ['Accept' => 'application/json'])->assertForbidden();
        $this->actingAs($manager)->post('/api/v1/academic/student-imports/preview', $payload + ['file' => $this->validFile()], ['Accept' => 'application/json'])->assertOk();
    }

    public function test_preview_marks_existing_matricule_as_invalid(): void
    {
        [$manager, $university, $year, $promotion] = $this->context();
        Student::factory()->create(['university_id' => $university->id, 'student_number' => 'MED-001']);

        $this->actingAs($manager)->post('/api/v1/academic/student-imports/preview', ['university_id' => $university->public_id, 'promotion_id' => $promotion->id, 'academic_year_id' => $year->id, 'file' => $this->validFile()], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('data.rows.0.valid', false)->assertJsonPath('data.rows.0.errors.0.code', 'ALREADY_EXISTS');
    }

    public function test_expired_or_tampered_registration_token_is_rejected_without_creating_user(): void
    {
        [, $university, , $promotion] = $this->context();
        $student = Student::factory()->create(['university_id' => $university->id, 'student_number' => 'MED-EXP']);
        $student->enrollments()->create(['promotion_id' => $promotion->id, 'status' => 'ACTIVE']);
        $expired = Crypt::encryptString(json_encode(['student_id' => $student->id, 'university_id' => $university->id, 'promotion_id' => $promotion->id, 'academic_year_id' => $promotion->academic_year_reference_id, 'expires_at' => now()->subMinute()->timestamp], JSON_THROW_ON_ERROR));
        $payload = ['email' => 'expired@example.test', 'password' => 'very-secure-password', 'password_confirmation' => 'very-secure-password'];

        $this->postJson('/api/v1/auth/student-registration', $payload + ['registration_token' => $expired])->assertUnprocessable();
        $this->postJson('/api/v1/auth/student-registration', $payload + ['registration_token' => 'tampered-token'])->assertUnprocessable();
        $this->assertDatabaseMissing('users', ['email' => 'expired@example.test']);
    }

    public function test_import_context_returns_validation_errors_instead_of_model_not_found(): void
    {
        [$manager, $university, $year] = $this->context();

        $this->actingAs($manager)->post('/api/v1/academic/student-imports/preview', [
            'university_id' => $university->public_id,
            'promotion_id' => 999999,
            'academic_year_id' => $year->id,
            'file' => $this->validFile(),
        ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('promotion_id');
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

    private function validFile(): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'medtrack-integration-sheet-');
        $writer = new Writer;
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues(['Matricule', 'Nom', 'Post-nom', 'Prénom', 'Sexe', 'Date de naissance']));
        $writer->addRow(Row::fromValues(['MED-001', 'Masiala', 'Phambu', 'Bruno', 'M', '2000-01-31']));
        $writer->close();

        return new UploadedFile($path, 'students.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }
}
