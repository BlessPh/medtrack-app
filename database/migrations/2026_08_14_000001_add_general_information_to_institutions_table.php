<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('institutions', function (Blueprint $table): void {
            $table->string('legal_form', 80)->nullable();
            $table->string('ownership_type', 30)->nullable();
            $table->string('accreditation_number', 100)->nullable()->index();
            $table->string('tax_number', 100)->nullable()->index();
            $table->date('founded_on')->nullable();
            $table->string('primary_language', 10)->nullable();
            $table->string('timezone', 100)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('institutions', function (Blueprint $table): void {
            $table->dropIndex(['accreditation_number']);
            $table->dropIndex(['tax_number']);
            $table->dropColumn(['legal_form', 'ownership_type', 'accreditation_number', 'tax_number', 'founded_on', 'primary_language', 'timezone']);
        });
    }
};
