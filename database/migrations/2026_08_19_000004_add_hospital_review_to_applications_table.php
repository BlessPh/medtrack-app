<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table): void {
            $table->foreignId('assigned_service_id')->nullable()->after('preferred_hospital_id')->constrained('institution_units')->nullOnDelete();
            $table->date('proposed_starts_on')->nullable()->after('assigned_service_id');
            $table->date('proposed_ends_on')->nullable()->after('proposed_starts_on');
            $table->text('internal_note')->nullable()->after('motivation');
        });
    }

    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table): void {
            $table->dropForeign(['assigned_service_id']);
            $table->dropColumn(['assigned_service_id', 'proposed_starts_on', 'proposed_ends_on', 'internal_note']);
        });
    }
};
