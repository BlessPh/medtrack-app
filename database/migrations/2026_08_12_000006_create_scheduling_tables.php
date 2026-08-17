<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('schedules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('internship_id')->constrained()->restrictOnDelete();
            $table->string('name', 200);
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('status', 20)->default('DRAFT');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('schedule_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('schedule_id')->constrained()->restrictOnDelete();
            $table->foreignId('rotation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('student_id')->constrained();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->string('activity_type', 30);
            $table->string('location')->nullable();
            $table->string('status', 20)->default('SCHEDULED');
            $table->timestamps();
            $table->index(['student_id', 'starts_at']);
        });

        Schema::create('biometric_devices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('institution_id')->constrained()->restrictOnDelete();
            $table->string('code', 100);
            $table->string('name', 150);
            $table->string('location')->nullable();
            $table->string('status', 20)->default('ACTIVE');
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
            $table->unique(['institution_id', 'code']);
        });

        Schema::create('attendance_records', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('student_id')->constrained();
            $table->foreignId('schedule_entry_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('biometric_device_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 20);
            $table->timestamp('recorded_at');
            $table->string('source', 20)->default('MANUAL');
            $table->string('status', 20)->default('VALID');
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['student_id', 'recorded_at']);
        });

        Schema::create('attendance_corrections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('attendance_record_id')->constrained()->restrictOnDelete();
            $table->foreignId('requested_by')->constrained('users');
            $table->timestamp('corrected_at')->nullable();
            $table->text('reason');
            $table->string('status', 20)->default('PENDING');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_corrections');
        Schema::dropIfExists('attendance_records');
        Schema::dropIfExists('biometric_devices');
        Schema::dropIfExists('schedule_entries');
        Schema::dropIfExists('schedules');
    }
};
