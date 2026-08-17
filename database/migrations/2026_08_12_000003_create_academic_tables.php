<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('academic_programs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('university_id')->constrained('institutions')->restrictOnDelete();
            $table->foreignId('faculty_unit_id')->nullable()->constrained('institution_units')->nullOnDelete();
            $table->string('code', 100);
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('degree_type', 50)->nullable();
            $table->unsignedSmallInteger('duration_years')->nullable();
            $table->string('status', 20)->default('ACTIVE')->index();
            $table->timestamps();
            $table->unique(['university_id', 'code']);
        });

        Schema::create('academic_levels', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('label', 150);
            $table->string('cycle', 30)->nullable();
            $table->string('internship_regime', 40)->nullable();
            $table->unsignedSmallInteger('display_order')->unique();
            $table->string('status', 20)->default('ACTIVE');
        });

        Schema::create('academic_years', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('institution_id')->constrained()->restrictOnDelete();
            $table->string('label', 30);
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('status', 20)->default('PLANNED');
            $table->timestamps();
            $table->unique(['institution_id', 'label']);
        });

        Schema::create('promotions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('program_id')->constrained('academic_programs')->restrictOnDelete();
            $table->foreignId('level_id')->constrained('academic_levels');
            $table->foreignId('academic_year_id')->constrained()->restrictOnDelete();
            $table->string('name', 150);
            $table->string('status', 20)->default('ACTIVE');
            $table->timestamps();
            $table->unique(['program_id', 'level_id', 'academic_year_id'], 'promotions_program_level_year_unique');
        });

        Schema::create('students', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('user_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->foreignId('university_id')->constrained('institutions');
            $table->string('national_reference', 150)->nullable();
            $table->string('student_number', 100)->nullable();
            $table->string('status', 20)->default('ACTIVE');
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['university_id', 'student_number']);
            $table->index(['university_id', 'status']);
        });

        Schema::create('enrollments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('student_id')->constrained()->restrictOnDelete();
            $table->foreignId('promotion_id')->constrained()->restrictOnDelete();
            $table->string('status', 20)->default('PENDING');
            $table->timestamp('enrolled_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['student_id', 'promotion_id']);
            $table->index(['promotion_id', 'status']);
        });

        Schema::create('campaigns', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('university_id')->constrained('institutions');
            $table->foreignId('academic_year_id')->constrained();
            $table->string('name', 200);
            $table->string('regime', 40)->nullable();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->string('status', 20)->default('DRAFT')->index();
            $table->json('rules')->nullable();
            $table->timestamps();
        });

        Schema::create('campaign_promotions', function (Blueprint $table): void {
            $table->foreignId('campaign_id')->constrained()->restrictOnDelete();
            $table->foreignId('promotion_id')->constrained()->restrictOnDelete();
            $table->primary(['campaign_id', 'promotion_id']);
        });

        Schema::create('campaign_hospitals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->restrictOnDelete();
            $table->foreignId('hospital_id')->constrained('institutions');
            $table->unsignedInteger('capacity')->default(0);
            $table->string('status', 20)->default('ACTIVE');
            $table->timestamps();
            $table->unique(['campaign_id', 'hospital_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_hospitals');
        Schema::dropIfExists('campaign_promotions');
        Schema::dropIfExists('campaigns');
        Schema::dropIfExists('enrollments');
        Schema::dropIfExists('students');
        Schema::dropIfExists('promotions');
        Schema::dropIfExists('academic_years');
        Schema::dropIfExists('academic_levels');
        Schema::dropIfExists('academic_programs');
    }
};
