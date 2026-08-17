<?php

namespace App\Modules\Finance\Controllers;

use App\Modules\Academic\Models\Student;
use App\Modules\Finance\Contracts\PaymentGateway;
use App\Modules\Finance\Models\FinancialObligation;
use App\Modules\Finance\Models\PaymentRefund;
use App\Modules\Finance\Models\PaymentTransaction;
use App\Modules\Finance\Services\FinanceService;
use App\Modules\Institution\Models\Institution;
use App\Shared\Enums\InstitutionRole;
use App\Shared\Services\InstitutionAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class FinanceController
{
    public function obligation(Request $request): JsonResponse
    {
        $data = $request->validate(['student_id' => ['required', 'uuid', 'exists:students,public_id'], 'institution_id' => ['required', 'uuid', 'exists:institutions,public_id'], 'type' => ['required', 'string', 'max:40'], 'description' => ['required', 'string'], 'currency' => ['required', 'string', 'size:3'], 'items' => ['required', 'array', 'min:1'], 'items.*.label' => ['required', 'string'], 'items.*.quantity' => ['required', 'numeric', 'gt:0'], 'items.*.unit_amount' => ['required', 'numeric', 'gte:0']]);
        $institution = Institution::where('public_id', $data['institution_id'])->firstOrFail();
        abort_unless(app(InstitutionAccess::class)->has($request->user(), $institution->id, [InstitutionRole::FinanceOfficer->value]), 403);
        $student = Student::where('public_id', $data['student_id'])->firstOrFail();
        $amount = collect($data['items'])->sum(fn ($item) => round($item['quantity'] * $item['unit_amount'], 2));
        $obligation = FinancialObligation::create(['student_id' => $student->id, 'institution_id' => $institution->id, 'type' => $data['type'], 'description' => $data['description'], 'amount' => $amount, 'currency' => strtoupper($data['currency']), 'status' => 'PENDING']);
        $obligation->items()->createMany($data['items']);

        return response()->json(['data' => $obligation->load('items')], 201);
    }

    public function pay(Request $request, PaymentGateway $gateway): JsonResponse
    {
        $data = $request->validate(['obligation_id' => ['required', 'uuid', 'exists:financial_obligations,public_id'], 'amount' => ['required', 'numeric', 'gt:0'], 'method' => ['required', 'string', 'max:30']]);
        $obligation = FinancialObligation::where('public_id', $data['obligation_id'])->firstOrFail();
        abort_unless($obligation->student->user_id === $request->user()->id, 403);
        abort_if((float) $data['amount'] > (float) $obligation->amount - (float) $obligation->paid_amount, 422);
        $reference = (string) Str::uuid();
        $gatewayData = $gateway->create($reference, number_format($data['amount'], 2, '.', ''), $obligation->currency);
        $transaction = PaymentTransaction::create(['student_id' => $obligation->student_id, 'provider' => 'MAISHAPAY', 'provider_reference' => $gatewayData['reference'], 'amount' => $data['amount'], 'currency' => $obligation->currency, 'method' => $data['method'], 'status' => 'PENDING']);

        return response()->json(['data' => $transaction], 201);
    }

    public function callback(Request $request, PaymentGateway $gateway, FinanceService $finance): JsonResponse
    {
        abort_unless($gateway->validSignature($request->getContent(), $request->header('X-MaishaPay-Signature')), 401);
        $data = $request->validate(['reference' => ['required', 'string'], 'status' => ['required', 'in:PAID,FAILED'], 'obligation_id' => ['nullable', 'uuid'], 'amount' => ['nullable', 'numeric']]);
        $transaction = PaymentTransaction::where('provider', 'MAISHAPAY')->where('provider_reference', $data['reference'])->firstOrFail();
        if ($transaction->status !== 'PENDING') {
            return response()->json(['data' => $transaction]);
        }
        $transaction->update(['status' => $data['status'], 'paid_at' => $data['status'] === 'PAID' ? now() : null]);
        if ($data['status'] === 'PAID' && isset($data['obligation_id'])) {
            $finance->allocate($transaction, FinancialObligation::where('public_id', $data['obligation_id'])->firstOrFail(), (string) ($data['amount'] ?? $transaction->amount));
        }

        return response()->json(['data' => $transaction->fresh('allocations')]);
    }

    public function refund(Request $request, PaymentTransaction $transaction, PaymentGateway $gateway): JsonResponse
    {
        abort_unless($transaction->student->user_id === $request->user()->id, 403);
        $data = $request->validate(['amount' => ['required', 'numeric', 'gt:0'], 'reason' => ['required', 'string']]);
        abort_unless($transaction->status === 'PAID' && (float) $data['amount'] <= (float) $transaction->amount - (float) $transaction->refunds()->where('status', 'PROCESSED')->sum('amount'), 422);
        $result = $gateway->refund($transaction->provider_reference, (string) $data['amount']);
        $refund = PaymentRefund::create(['transaction_id' => $transaction->id, 'amount' => $data['amount'], 'reason' => $data['reason'], 'status' => $result['status'], 'provider_reference' => $result['reference'], 'requested_by' => $request->user()->id, 'processed_at' => now()]);

        return response()->json(['data' => $refund], 201);
    }

    public function receipt(Request $request, PaymentTransaction $transaction): JsonResponse
    {
        abort_unless($transaction->student->user_id === $request->user()->id, 403);

        return response()->json(['data' => $transaction->load('allocations.obligation')]);
    }
}
