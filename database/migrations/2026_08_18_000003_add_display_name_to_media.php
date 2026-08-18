<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media', function (Blueprint $table): void {
            $table->string('display_name', 200)->nullable()->after('original_name');
        });

        DB::table('media')->whereNull('display_name')->update(['display_name' => DB::raw('original_name')]);
    }

    public function down(): void
    {
        Schema::table('media', fn (Blueprint $table) => $table->dropColumn('display_name'));
    }
};
