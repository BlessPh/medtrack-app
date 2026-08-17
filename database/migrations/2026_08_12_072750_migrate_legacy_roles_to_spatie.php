<?php

use App\Modules\Auth\Models\User;
use App\Shared\Enums\InstitutionRole;
use App\Shared\Enums\UserRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        $roleNames = collect([UserRole::SuperAdmin, ...InstitutionRole::cases()])
            ->map(fn ($role): string => $role->value)
            ->unique();

        foreach ($roleNames as $name) {
            DB::table('roles')->insertOrIgnore([
                'institution_id' => null,
                'name' => $name,
                'guard_name' => 'web',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        if (Schema::hasColumn('users', 'role')) {
            $superAdminRoleId = DB::table('roles')->whereNull('institution_id')
                ->where('name', UserRole::SuperAdmin->value)->where('guard_name', 'web')->value('id');

            DB::table('users')->where('role', UserRole::SuperAdmin->value)->orderBy('id')
                ->chunkById(500, function ($users) use ($superAdminRoleId): void {
                    foreach ($users as $user) {
                        DB::table('model_has_roles')->insertOrIgnore([
                            'role_id' => $superAdminRoleId,
                            'model_type' => User::class,
                            'model_id' => $user->id,
                            'institution_id' => 0,
                        ]);
                    }
                });

            Schema::table('users', function (Blueprint $table): void {
                $table->dropIndex('users_role_index');
                $table->dropColumn('role');
            });
        }

        if (Schema::hasColumn('institution_memberships', 'role')) {
            DB::table('institution_memberships')->orderBy('user_id')->chunk(500, function ($memberships): void {
                foreach ($memberships as $membership) {
                    $roleId = DB::table('roles')->whereNull('institution_id')
                        ->where('name', $membership->role)->where('guard_name', 'web')->value('id');

                    if ($roleId) {
                        DB::table('model_has_roles')->insertOrIgnore([
                            'role_id' => $roleId,
                            'model_type' => User::class,
                            'model_id' => $membership->user_id,
                            'institution_id' => $membership->institution_id,
                        ]);
                    }
                }
            });

            Schema::table('institution_memberships', function (Blueprint $table): void {
                $table->dropIndex('institution_memberships_user_id_role_index');
                $table->dropColumn('role');
                $table->index('user_id');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('users', 'role')) {
            Schema::table('users', fn (Blueprint $table) => $table->string('role', 30)->default('USER')->index());
        }

        if (! Schema::hasColumn('institution_memberships', 'role')) {
            Schema::table('institution_memberships', function (Blueprint $table): void {
                $table->dropIndex(['user_id']);
                $table->string('role', 30)->default('MEMBER');
                $table->index(['user_id', 'role']);
            });
        }
    }
};
