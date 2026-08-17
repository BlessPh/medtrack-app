<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('academic_year_references', function (Blueprint $table): void {
            $table->id();
            $table->string('label', 30)->unique();
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('status', 20)->default('UPCOMING')->index();
            $table->boolean('is_current')->default(false)->index();
            $table->timestamps();
        });

        foreach (DB::table('academic_years')->orderBy('starts_on')->get()->groupBy('label') as $label => $years) {
            $source = $years->sortByDesc(fn ($year) => $year->status === 'ACTIVE')->first();
            DB::table('academic_year_references')->insert([
                'label' => $label, 'starts_on' => $source->starts_on, 'ends_on' => $source->ends_on,
                'status' => $source->status === 'ACTIVE' ? 'CURRENT' : ($source->status === 'COMPLETED' ? 'PAST' : 'UPCOMING'),
                'is_current' => $source->status === 'ACTIVE', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        Schema::table('promotions', function (Blueprint $table): void {
            $table->foreignId('academic_year_reference_id')->nullable()->after('academic_year_id')->constrained('academic_year_references')->restrictOnDelete();
        });
        Schema::table('campaigns', function (Blueprint $table): void {
            $table->foreignId('academic_year_reference_id')->nullable()->after('academic_year_id')->constrained('academic_year_references')->restrictOnDelete();
        });

        foreach (DB::table('academic_years')->get() as $legacy) {
            $referenceId = DB::table('academic_year_references')->where('label', $legacy->label)->value('id');
            DB::table('promotions')->where('academic_year_id', $legacy->id)->update(['academic_year_reference_id' => $referenceId]);
            DB::table('campaigns')->where('academic_year_id', $legacy->id)->update(['academic_year_reference_id' => $referenceId]);
        }

        Schema::table('promotions', function (Blueprint $table): void {
            $table->foreignId('academic_year_id')->nullable()->change();
            $table->unique(['program_id', 'level_id', 'academic_year_reference_id'], 'promotions_program_level_reference_unique');
        });
        Schema::table('campaigns', function (Blueprint $table): void {
            $table->foreignId('academic_year_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('campaigns', fn (Blueprint $table) => $table->dropConstrainedForeignId('academic_year_reference_id'));
        Schema::table('promotions', function (Blueprint $table): void {
            $table->dropUnique('promotions_program_level_reference_unique');
            $table->dropConstrainedForeignId('academic_year_reference_id');
        });
        Schema::dropIfExists('academic_year_references');
    }
};
