<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('evaluation_templates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('institution_id')->constrained()->restrictOnDelete();
            $table->string('name', 200);
            $table->json('criteria');
            $table->decimal('maximum_score', 7, 2)->default(100);
            $table->string('status', 20)->default('ACTIVE');
            $table->timestamps();
        });

        Schema::create('evaluations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('rotation_id')->constrained()->restrictOnDelete();
            $table->foreignId('template_id')->nullable()->constrained('evaluation_templates')->nullOnDelete();
            $table->foreignId('student_id')->constrained();
            $table->foreignId('evaluator_id')->constrained('users');
            $table->decimal('score', 7, 2)->nullable();
            $table->json('answers')->nullable();
            $table->text('comments')->nullable();
            $table->string('status', 20)->default('DRAFT');
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();
            $table->unique(['rotation_id', 'evaluator_id']);
            $table->index(['student_id', 'status']);
        });

        Schema::create('evaluation_disputes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('evaluation_id')->constrained()->restrictOnDelete();
            $table->foreignId('opened_by')->constrained('users');
            $table->text('reason');
            $table->string('status', 20)->default('OPEN');
            $table->text('resolution')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });

        Schema::create('academic_decisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('student_id')->constrained();
            $table->foreignId('internship_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('academic_year_id')->nullable()->constrained()->nullOnDelete();
            $table->string('decision', 30);
            $table->decimal('final_score', 7, 2)->nullable();
            $table->text('comments')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
            $table->unique(['student_id', 'internship_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('academic_decisions');
        Schema::dropIfExists('evaluation_disputes');
        Schema::dropIfExists('evaluations');
        Schema::dropIfExists('evaluation_templates');
    }
};
