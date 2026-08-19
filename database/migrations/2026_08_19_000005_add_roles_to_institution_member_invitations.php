<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('institution_member_invitations', fn (Blueprint $table) => $table->json('roles')->nullable()->after('role'));
        DB::table('institution_member_invitations')->orderBy('id')->each(fn ($item) => DB::table('institution_member_invitations')->where('id', $item->id)->update(['roles' => json_encode([$item->role])]));
    }

    public function down(): void
    {
        Schema::table('institution_member_invitations', fn (Blueprint $table) => $table->dropColumn('roles'));
    }
};
