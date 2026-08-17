<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('path_templates', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 200);
            $table->string('regime', 40)->nullable();
            $table->string('status', 20)->default('ACTIVE');
            $table->timestamps();
        });

        Schema::create('path_steps', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('path_template_id')->constrained()->restrictOnDelete();
            $table->string('name', 200);
            $table->string('specialty', 100)->nullable();
            $table->unsignedInteger('duration_days');
            $table->unsignedSmallInteger('position');
            $table->boolean('required')->default(true);
            $table->unique(['path_template_id', 'position']);
        });

        Schema::create('internships', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('admission_id')->unique()->constrained();
            $table->foreignId('student_id')->constrained();
            $table->foreignId('hospital_id')->constrained('institutions');
            $table->foreignId('path_template_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('supervisor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->string('status', 30)->default('PLANNED');
            $table->timestamps();
            $table->index(['student_id', 'status']);
            $table->index(['hospital_id', 'status']);
        });

        Schema::create('rotations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('internship_id')->constrained()->restrictOnDelete();
            $table->foreignId('path_step_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('institution_unit_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('supervisor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('status', 20)->default('PLANNED');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['internship_id', 'status']);
        });

        Schema::create('rotation_extensions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('rotation_id')->constrained()->restrictOnDelete();
            $table->date('previous_end_date');
            $table->date('new_end_date');
            $table->text('reason');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rotation_extensions');
        Schema::dropIfExists('rotations');
        Schema::dropIfExists('internships');
        Schema::dropIfExists('path_steps');
        Schema::dropIfExists('path_templates');
    }
};
