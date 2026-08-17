<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('institution_account_requests', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('reference', 30)->unique();
            $table->string('institution_type', 20)->index();
            $table->string('institution_name');
            $table->string('last_name', 100);
            $table->string('middle_name', 100)->nullable();
            $table->string('first_name', 100);
            $table->string('gender', 20)->nullable();
            $table->string('email')->index();
            $table->string('phone', 30);
            $table->string('job_title', 150);
            $table->string('password_hash');
            $table->string('status', 20)->default('PENDING')->index();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_user_id')->nullable()->unique()->constrained('users')->nullOnDelete();
            $table->text('rejection_reason')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->index(['email', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('institution_account_requests');
    }
};
