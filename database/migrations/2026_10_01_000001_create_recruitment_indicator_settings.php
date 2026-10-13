<?php

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

/**
 * Matching-indicator rules HR can change without a deploy.
 *
 * One row holding only what differs from config('recruitment.indicators');
 * a missing key means "use the config value". The permission is
 * granted to no role (see never_granted_to_roles): administrator roles pass
 * without it, and anyone else is given it per user from Access Management.
 */
return new class extends Migration
{
    private const PERMISSION = 'recruitment-indicators.manage';

    public function up(): void
    {
        Schema::create('recruitment_indicator_settings', function (Blueprint $table) {
            $table->id();
            $table->json('overrides_json');
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Permission::findOrCreate(self::PERMISSION, 'web');

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::dropIfExists('recruitment_indicator_settings');
        Permission::query()->where('name', self::PERMISSION)->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
