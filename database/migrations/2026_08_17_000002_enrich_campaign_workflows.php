<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $table): void {
            $table->string('strategy', 30)->default('STANDARD')->after('regime')->index();
            $table->text('instructions')->nullable()->after('strategy');
        });
        Schema::table('campaign_hospitals', function (Blueprint $table): void {
            $table->string('request_status', 20)->default('PENDING')->after('status')->index();
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->text('response_note')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('campaign_hospitals', fn (Blueprint $table) => $table->dropColumn(['request_status', 'requested_at', 'responded_at', 'response_note']));
        Schema::table('campaigns', fn (Blueprint $table) => $table->dropColumn(['strategy', 'instructions']));
    }
};
