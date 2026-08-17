<?php

namespace Tests\Feature;

use App\Modules\Academic\Models\Student;
use App\Modules\Auth\Models\User;
use App\Modules\Finance\Models\FinancialObligation;
use App\Modules\Finance\Models\PaymentTransaction;
use App\Modules\Finance\Services\FinanceService;
use App\Modules\Institution\Models\Institution;
use App\Modules\Media\Models\Document;
use App\Modules\Notification\Notifications\AdmissionCreatedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ComplementaryServicesTest extends TestCase
{
    use RefreshDatabase;

    public function test_payment_allocation_updates_balance_and_refuses_overallocation(): void
    {
        $student = Student::factory()->create(['university_id' => Institution::factory()->create()->id]);
        $obligation = FinancialObligation::create(['student_id' => $student->id, 'institution_id' => $student->university_id, 'type' => 'FEE', 'description' => 'Frais', 'amount' => 100, 'currency' => 'USD', 'status' => 'PENDING']);
        $transaction = PaymentTransaction::create(['student_id' => $student->id, 'provider' => 'TEST', 'provider_reference' => 'PAY-1', 'amount' => 100, 'currency' => 'USD', 'status' => 'PAID']);
        app(FinanceService::class)->allocate($transaction, $obligation, '80');
        $this->assertDatabaseHas('financial_obligations', ['id' => $obligation->id, 'paid_amount' => 80, 'status' => 'PARTIALLY_PAID']);

        $this->expectException(ValidationException::class);
        app(FinanceService::class)->allocate($transaction, $obligation, '30');
    }

    public function test_invalid_maishapay_callback_signature_is_rejected(): void
    {
        $this->postJson('/api/v1/finance/callbacks/maishapay', ['reference' => 'unknown', 'status' => 'PAID'], ['X-MaishaPay-Signature' => 'invalid'])->assertUnauthorized();
    }

    public function test_student_uploads_and_downloads_private_document_but_another_user_cannot(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $student = Student::factory()->create(['user_id' => $user->id, 'university_id' => Institution::factory()->create()->id]);
        $documentId = $this->actingAs($user)->post('/api/v1/documents', ['owner_type' => 'student', 'owner_id' => $student->public_id, 'collection' => 'identity', 'file' => UploadedFile::fake()->create('identity.pdf', 20, 'application/pdf')], ['Accept' => 'application/json'])->assertCreated()->json('data.public_id');
        $document = Document::where('public_id', $documentId)->firstOrFail();
        Storage::disk('local')->assertExists($document->path);
        $this->get("/api/v1/documents/{$documentId}/download")->assertOk();
        $this->actingAs(User::factory()->create())->get("/api/v1/documents/{$documentId}/download")->assertForbidden();
    }

    public function test_user_can_list_and_mark_notifications_as_read(): void
    {
        $user = User::factory()->create();
        $user->notify(new AdmissionCreatedNotification('admission-test'));
        $id = $user->notifications()->value('id');

        $this->actingAs($user)->getJson('/api/v1/notifications')->assertOk();
        $this->patchJson("/api/v1/notifications/{$id}/read")->assertOk();
        $this->assertNotNull($user->notifications()->find($id)->read_at);
    }
}
