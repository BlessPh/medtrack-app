<?php

namespace Tests\Feature;

use App\Modules\Academic\Models\Student;
use App\Modules\Auth\Models\User;
use App\Modules\Finance\Models\FinancialObligation;
use App\Modules\Institution\Models\Institution;
use App\Shared\Enums\InstitutionRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FinanceHospitalManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_finance_officer_can_manage_only_their_hospital_finances(): void
    {
        $hospital = Institution::factory()->create(['type' => 'HOSPITAL']);
        $otherHospital = Institution::factory()->create(['type' => 'HOSPITAL']);
        $university = Institution::factory()->create(['type' => 'UNIVERSITY']);
        $officer = User::factory()->create();
        $this->assignInstitutionRole($officer, $hospital, InstitutionRole::FinanceOfficer->value);
        $student = Student::factory()->create(['university_id' => $university->id]);
        $obligation = FinancialObligation::create(['student_id' => $student->id, 'institution_id' => $hospital->id, 'type' => 'STAGE_FEE', 'description' => 'Frais de stage', 'amount' => 100, 'currency' => 'USD', 'status' => 'PENDING']);

        $this->actingAs($officer)->getJson("/api/v1/finance/dashboard?institution_id={$hospital->public_id}")->assertOk()->assertJsonPath('data.statistics.open_obligations', 1);
        $this->getJson("/api/v1/finance/dashboard?institution_id={$otherHospital->public_id}")->assertForbidden();

        $transactionId = $this->postJson('/api/v1/finance/manual-payments', [
            'institution_id' => $hospital->public_id, 'obligation_id' => $obligation->public_id,
            'amount' => 100, 'currency' => 'USD', 'method' => 'CASH', 'payer_reference' => 'RECU-2026-001',
            'paid_at' => now()->toDateString(),
        ])->assertCreated()->assertJsonPath('data.source', 'MANUAL')->json('data.public_id');

        $this->patchJson("/api/v1/finance/transactions/{$transactionId}/verify")->assertOk()->assertJsonPath('data.verified_by.id', $officer->id);
        $this->postJson("/api/v1/finance/transactions/{$transactionId}/refunds", ['amount' => 20, 'reason' => 'Trop-perçu'])->assertCreated();
        $this->getJson("/api/v1/finance/refunds?institution_id={$hospital->public_id}")->assertOk()->assertJsonCount(1, 'data.data');
        $this->assertDatabaseHas('financial_obligations', ['id' => $obligation->id, 'status' => 'PAID', 'paid_amount' => 100]);
    }

    public function test_cumulative_roles_preserve_finance_access_but_admin_role_alone_does_not_grant_it(): void
    {
        $hospital = Institution::factory()->create(['type' => 'HOSPITAL']);
        $multiRoleUser = User::factory()->create();
        $adminOnly = User::factory()->create();
        $this->assignInstitutionRole($multiRoleUser, $hospital, InstitutionRole::Admin->value);
        $this->assignInstitutionRole($multiRoleUser, $hospital, InstitutionRole::FinanceOfficer->value);
        $this->assignInstitutionRole($adminOnly, $hospital, InstitutionRole::Admin->value);

        $this->actingAs($multiRoleUser)->getJson("/api/v1/finance/dashboard?institution_id={$hospital->public_id}")->assertOk();
        $this->actingAs($adminOnly)->getJson("/api/v1/finance/dashboard?institution_id={$hospital->public_id}")->assertForbidden();
    }

    public function test_academic_manager_with_finance_role_can_access_their_university_finances(): void
    {
        $university = Institution::factory()->create(['type' => 'UNIVERSITY']);
        $otherUniversity = Institution::factory()->create(['type' => 'UNIVERSITY']);
        $user = User::factory()->create();
        $this->assignInstitutionRole($user, $university, InstitutionRole::AcademicManager->value);
        $this->assignInstitutionRole($user, $university, InstitutionRole::FinanceOfficer->value);
        $student = Student::factory()->create(['university_id' => $university->id]);
        FinancialObligation::create([
            'student_id' => $student->id,
            'institution_id' => $university->id,
            'type' => 'ACADEMIC_FEE',
            'description' => 'Frais académiques',
            'amount' => 50,
            'currency' => 'USD',
            'status' => 'PENDING',
        ]);

        $this->actingAs($user)
            ->getJson("/api/v1/finance/dashboard?institution_id={$university->public_id}")
            ->assertOk()
            ->assertJsonPath('data.statistics.open_obligations', 1);
        $this->getJson("/api/v1/finance/context?institution_id={$university->public_id}")
            ->assertOk()
            ->assertJsonPath('data.students.0.id', $student->public_id);
        $this->getJson("/api/v1/finance/dashboard?institution_id={$otherUniversity->public_id}")
            ->assertForbidden();
    }
}
