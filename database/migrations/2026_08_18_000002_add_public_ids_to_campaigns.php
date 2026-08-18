<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $table): void {
            $table->uuid('public_id')->nullable()->unique()->after('id');
        });
        Schema::table('campaign_hospitals', function (Blueprint $table): void {
            $table->uuid('public_id')->nullable()->unique()->after('id');
        });

        DB::table('campaigns')->whereNull('public_id')->orderBy('id')->eachById(
            fn ($campaign) => DB::table('campaigns')->where('id', $campaign->id)->update(['public_id' => (string) Str::uuid()])
        );
        DB::table('campaign_hospitals')->whereNull('public_id')->orderBy('id')->eachById(
            fn ($request) => DB::table('campaign_hospitals')->where('id', $request->id)->update(['public_id' => (string) Str::uuid()])
        );
    }

    public function down(): void
    {
        Schema::table('campaign_hospitals', fn (Blueprint $table) => $table->dropColumn('public_id'));
        Schema::table('campaigns', fn (Blueprint $table) => $table->dropColumn('public_id'));
    }
};
