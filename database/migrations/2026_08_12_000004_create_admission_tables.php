<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('applications', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('campaign_id')->constrained();
            $table->foreignId('student_id')->constrained();
            $table->foreignId('preferred_hospital_id')->nullable()->constrained('institutions')->nullOnDelete();
            $table->string('status', 30)->default('SUBMITTED');
            $table->text('motivation')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('review_note')->nullable();
            $table->timestamps();
            $table->unique(['campaign_id', 'student_id']);
            $table->index(['campaign_id', 'status']);
        });

        Schema::create('capacity_pools', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('campaign_hospital_id')->constrained()->restrictOnDelete();
            $table->foreignId('level_id')->nullable()->constrained('academic_levels')->nullOnDelete();
            $table->unsignedInteger('total_places');
            $table->unsignedInteger('reserved_places')->default(0);
            $table->timestamps();
            $table->unique(['campaign_hospital_id', 'level_id']);
        });

        Schema::create('capacity_reservations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('capacity_pool_id')->constrained()->restrictOnDelete();
            $table->foreignId('application_id')->unique()->constrained()->restrictOnDelete();
            $table->string('status', 20)->default('HELD');
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });

        Schema::create('admissions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('application_id')->unique()->constrained();
            $table->foreignId('student_id')->constrained();
            $table->foreignId('hospital_id')->constrained('institutions');
            $table->string('status', 20)->default('ACCEPTED');
            $table->timestamp('admitted_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
            $table->index(['hospital_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admissions');
        Schema::dropIfExists('capacity_reservations');
        Schema::dropIfExists('capacity_pools');
        Schema::dropIfExists('applications');
    }
};
