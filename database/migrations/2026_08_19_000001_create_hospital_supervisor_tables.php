<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hospital_supervisor_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('institution_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('availability_status', 20)->default('AVAILABLE')->index();
            $table->boolean('stages_enabled')->default(true);
            $table->text('availability_note')->nullable();
            $table->timestamps();
            $table->unique(['institution_id', 'user_id']);
        });

        Schema::create('hospital_supervisor_services', function (Blueprint $table): void {
            $table->foreignId('supervisor_profile_id')->constrained('hospital_supervisor_profiles')->cascadeOnDelete();
            $table->foreignId('institution_unit_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->primary(['supervisor_profile_id', 'institution_unit_id'], 'hospital_supervisor_services_primary');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hospital_supervisor_services');
        Schema::dropIfExists('hospital_supervisor_profiles');
    }
};
