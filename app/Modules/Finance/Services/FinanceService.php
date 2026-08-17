<?php

namespace App\Modules\Finance\Services;

use App\Modules\Finance\Models\FinancialObligation;
use App\Modules\Finance\Models\PaymentAllocation;
use App\Modules\Finance\Models\PaymentTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FinanceService
{
    public function allocate(PaymentTransaction $transaction, FinancialObligation $obligation, string $amount): PaymentAllocation
    {
        return DB::transaction(function () use ($transaction, $obligation, $amount): PaymentAllocation {
            $transaction = PaymentTransaction::lockForUpdate()->findOrFail($transaction->id);
            $obligation = FinancialObligation::lockForUpdate()->findOrFail($obligation->id);
            $value = round((float) $amount, 2);
            $allocated = (float) $transaction->allocations()->sum('amount');
            $balance = (float) $obligation->amount - (float) $obligation->paid_amount;
            if ($transaction->status !== 'PAID' || $value <= 0 || $allocated + $value > (float) $transaction->amount || $value > $balance) {
                throw ValidationException::withMessages(['amount' => 'Allocation supérieure au montant disponible.']);
            }
            $allocation = PaymentAllocation::create(['transaction_id' => $transaction->id, 'obligation_id' => $obligation->id, 'amount' => $value]);
            $obligation->increment('paid_amount', $value);
            $obligation->update(['status' => (float) $obligation->fresh()->paid_amount >= (float) $obligation->amount ? 'PAID' : 'PARTIALLY_PAID']);

            return $allocation;
        }, 3);
    }
}
