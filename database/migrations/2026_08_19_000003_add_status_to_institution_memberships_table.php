<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('institution_memberships', function (Blueprint $table): void {
            $table->string('status', 20)->default('ACTIVE')->after('user_id')->index();
            $table->timestamp('suspended_at')->nullable()->after('status');
            $table->foreignId('suspended_by')->nullable()->after('suspended_at')->constrained('users')->nullOnDelete();
            $table->string('suspension_reason', 500)->nullable()->after('suspended_by');
        });
    }

    public function down(): void
    {
        Schema::table('institution_memberships', function (Blueprint $table): void {
            $table->dropForeign(['suspended_by']);
            $table->dropColumn(['status', 'suspended_at', 'suspended_by', 'suspension_reason']);
        });
    }
};
