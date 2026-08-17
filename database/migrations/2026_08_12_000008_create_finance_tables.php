<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_obligations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('student_id')->constrained();
            $table->foreignId('institution_id')->constrained();
            $table->foreignId('campaign_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 40);
            $table->string('description');
            $table->decimal('amount', 14, 2);
            $table->char('currency', 3)->default('CDF');
            $table->decimal('paid_amount', 14, 2)->default(0);
            $table->date('due_date')->nullable();
            $table->string('status', 20)->default('PENDING');
            $table->timestamps();
            $table->index(['student_id', 'status']);
        });

        Schema::create('financial_obligation_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('obligation_id')->constrained('financial_obligations')->restrictOnDelete();
            $table->string('label');
            $table->decimal('quantity', 10, 2)->default(1);
            $table->decimal('unit_amount', 14, 2);
            $table->timestamps();
        });

        Schema::create('payment_transactions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('student_id')->constrained();
            $table->string('provider', 50);
            $table->string('provider_reference', 150)->nullable();
            $table->decimal('amount', 14, 2);
            $table->char('currency', 3)->default('CDF');
            $table->string('method', 30)->nullable();
            $table->string('status', 20)->default('PENDING');
            $table->text('failure_reason')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
            $table->unique(['provider', 'provider_reference']);
            $table->index(['student_id', 'status']);
        });

        Schema::create('payment_allocations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('transaction_id')->constrained('payment_transactions')->restrictOnDelete();
            $table->foreignId('obligation_id')->constrained('financial_obligations');
            $table->decimal('amount', 14, 2);
            $table->timestamps();
            $table->unique(['transaction_id', 'obligation_id']);
        });

        Schema::create('payment_refunds', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('transaction_id')->constrained('payment_transactions');
            $table->decimal('amount', 14, 2);
            $table->text('reason');
            $table->string('status', 20)->default('PENDING');
            $table->string('provider_reference', 150)->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_refunds');
        Schema::dropIfExists('payment_allocations');
        Schema::dropIfExists('payment_transactions');
        Schema::dropIfExists('financial_obligation_items');
        Schema::dropIfExists('financial_obligations');
    }
};
