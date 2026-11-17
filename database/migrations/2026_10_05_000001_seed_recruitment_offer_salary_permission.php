<?php

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\PermissionRegistrar;

/**
 * The salary of a sent job offer, for the manager who requested the position
 * (RecruitmentJobOfferPolicy::viewSalary). Created only: no role gets it
 * here. It is granted on purpose, from Access Management, to the manager role
 * or to a single user. HR already see pay through User::canViewSalary(), and
 * super-admin stays salary-blind whatever it holds.
 */
return new class extends Migration
{
    private const NAME = 'recruitment-applicants.view-offer-salary';

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::findOrCreate(self::NAME, 'web');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::query()->where('name', self::NAME)->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
