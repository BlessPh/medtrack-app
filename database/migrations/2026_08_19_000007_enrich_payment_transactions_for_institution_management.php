<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_transactions', function (Blueprint $table): void {
            $table->foreignId('institution_id')->nullable()->after('student_id')->constrained('institutions')->nullOnDelete();
            $table->string('payer_reference', 150)->nullable()->after('provider_reference');
            $table->string('source', 20)->default('ONLINE')->after('method');
            $table->text('notes')->nullable()->after('failure_reason');
            $table->foreignId('recorded_by')->nullable()->after('notes')->constrained('users')->nullOnDelete();
            $table->foreignId('verified_by')->nullable()->after('recorded_by')->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable()->after('verified_by');
            $table->index(['institution_id', 'status']);
            $table->index(['institution_id', 'created_at']);
        });

        DB::table('payment_transactions')->whereNull('institution_id')->orderBy('id')->eachById(function ($transaction): void {
            $institutionId = DB::table('payment_allocations')
                ->join('financial_obligations', 'financial_obligations.id', '=', 'payment_allocations.obligation_id')
                ->where('payment_allocations.transaction_id', $transaction->id)
                ->value('financial_obligations.institution_id');
            if ($institutionId) DB::table('payment_transactions')->where('id', $transaction->id)->update(['institution_id' => $institutionId]);
        });
    }

    public function down(): void
    {
        Schema::table('payment_transactions', function (Blueprint $table): void {
            $table->dropForeign(['institution_id']);
            $table->dropForeign(['recorded_by']);
            $table->dropForeign(['verified_by']);
            $table->dropIndex(['institution_id', 'status']);
            $table->dropIndex(['institution_id', 'created_at']);
            $table->dropColumn(['institution_id', 'payer_reference', 'source', 'notes', 'recorded_by', 'verified_by', 'verified_at']);
        });
    }
};
