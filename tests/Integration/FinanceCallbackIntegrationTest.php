<?php

namespace Tests\Integration;

use App\Modules\Academic\Models\Student;
use App\Modules\Auth\Models\User;
use App\Modules\Finance\Models\FinancialObligation;
use App\Modules\Finance\Models\PaymentTransaction;
use App\Modules\Institution\Models\Institution;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FinanceCallbackIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_signed_callback_is_idempotent_and_allocates_payment_once(): void
    {
        config()->set('services.maishapay.webhook_secret', 'callback-secret');
        [$user, $student, $obligation, $transaction] = $this->paymentContext();
        $payload = ['reference' => $transaction->provider_reference, 'status' => 'PAID', 'obligation_id' => $obligation->public_id, 'amount' => 100];
        $content = json_encode($payload, JSON_THROW_ON_ERROR);
        $headers = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_MAISHAPAY_SIGNATURE' => hash_hmac('sha256', $content, 'callback-secret')];

        $this->call('POST', '/api/v1/finance/callbacks/maishapay', server: $headers, content: $content)->assertOk();
        $this->call('POST', '/api/v1/finance/callbacks/maishapay', server: $headers, content: $content)->assertOk();
        $this->assertDatabaseCount('payment_allocations', 1);
        $this->assertDatabaseHas('financial_obligations', ['id' => $obligation->id, 'paid_amount' => 100, 'status' => 'PAID']);
    }

    public function test_refund_is_owner_only_and_cannot_exceed_paid_amount(): void
    {
        [$user, , , $transaction] = $this->paymentContext(['status' => 'PAID']);
        $this->actingAs(User::factory()->create())->postJson("/api/v1/finance/transactions/{$transaction->public_id}/refunds", ['amount' => 20, 'reason' => 'Erreur'])->assertForbidden();
        $this->actingAs($user)->postJson("/api/v1/finance/transactions/{$transaction->public_id}/refunds", ['amount' => 101, 'reason' => 'Erreur'])->assertUnprocessable();
        $this->postJson("/api/v1/finance/transactions/{$transaction->public_id}/refunds", ['amount' => 20, 'reason' => 'Erreur'])->assertCreated();
        $this->assertDatabaseHas('payment_refunds', ['transaction_id' => $transaction->id, 'amount' => 20, 'status' => 'PROCESSED']);
    }

    private function paymentContext(array $transactionAttributes = []): array
    {
        $user = User::factory()->create();
        $institution = Institution::factory()->create();
        $student = Student::factory()->create(['user_id' => $user->id, 'university_id' => $institution->id]);
        $obligation = FinancialObligation::create(['student_id' => $student->id, 'institution_id' => $institution->id, 'type' => 'FEE', 'description' => 'Frais', 'amount' => 100, 'currency' => 'USD', 'status' => 'PENDING']);
        $transaction = PaymentTransaction::create(array_merge(['student_id' => $student->id, 'provider' => 'MAISHAPAY', 'provider_reference' => 'PAY-INTEGRATION', 'amount' => 100, 'currency' => 'USD', 'status' => 'PENDING'], $transactionAttributes));

        return [$user, $student, $obligation, $transaction];
    }
}
