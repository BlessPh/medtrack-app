<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table): void {
            $table->string('last_name', 100)->nullable()->after('student_number');
            $table->string('middle_name', 100)->nullable()->after('last_name');
            $table->string('first_name', 100)->nullable()->after('middle_name');
            $table->string('gender', 20)->nullable()->after('first_name');
            $table->date('birth_date')->nullable()->after('gender');
            $table->string('email')->nullable()->after('birth_date');
            $table->string('phone', 30)->nullable()->after('email');
        });

        DB::table('students')->orderBy('id')->chunkById(200, function ($students): void {
            foreach ($students as $student) {
                $metadata = json_decode($student->metadata ?? '{}', true) ?: [];
                DB::table('students')->where('id', $student->id)->update([
                    'last_name' => $metadata['last_name'] ?? null,
                    'middle_name' => $metadata['middle_name'] ?? null,
                    'first_name' => $metadata['first_name'] ?? null,
                    'gender' => $metadata['gender'] ?? null,
                    'birth_date' => $metadata['birth_date'] ?? null,
                    'email' => $metadata['email'] ?? null,
                    'phone' => $metadata['phone'] ?? null,
                ]);
            }
        });

        Schema::create('student_imports', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('university_id')->constrained('institutions')->restrictOnDelete();
            $table->foreignId('promotion_id')->constrained('promotions')->restrictOnDelete();
            $table->foreignId('academic_year_reference_id')->constrained('academic_year_references')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('original_filename');
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('valid_rows')->default(0);
            $table->unsignedInteger('rejected_rows')->default(0);
            $table->unsignedInteger('imported_rows')->default(0);
            $table->string('status', 20)->default('PREVIEWED')->index();
            $table->json('error_report')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
            $table->index(['university_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_imports');
        Schema::table('students', function (Blueprint $table): void {
            $table->dropColumn(['last_name', 'middle_name', 'first_name', 'gender', 'birth_date', 'email', 'phone']);
        });
    }
};
