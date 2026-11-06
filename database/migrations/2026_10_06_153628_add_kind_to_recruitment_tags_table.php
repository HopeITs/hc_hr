<?php

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('recruitment_tags', function (Blueprint $table) {
            $table->string('kind', 20)->default('neutral')->after('color')->index();
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permission = Permission::findOrCreate('recruitment-applicants.tag', 'web');

        foreach (['manager', 'general-manager'] as $roleName) {
            Role::findOrCreate($roleName, 'web')->givePermissionTo($permission);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permission = Permission::query()
            ->where('name', 'recruitment-applicants.tag')
            ->where('guard_name', 'web')
            ->first();

        if ($permission !== null) {
            Role::query()
                ->whereIn('name', ['manager', 'general-manager'])
                ->get()
                ->each(fn (Role $role) => $role->revokePermissionTo($permission));
        }

        Schema::table('recruitment_tags', function (Blueprint $table) {
            $table->dropColumn('kind');
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
