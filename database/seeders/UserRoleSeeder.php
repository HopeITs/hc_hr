<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use LogicException;

class UserRoleSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new LogicException('Development users must never be seeded outside local or testing environments.');
        }

        $password = env('LOCAL_SEED_PASSWORD', Str::password(24));

        $this->createUserWithRole(
            email: 'superadmin@mail.com',
            password: $password,
            roleName: 'manager',
            employee_id: 1,
            extraRoles: ['employee', 'general-manager', 'HR-ALP', 'HR-DMS', 'HR-HMS', 'HR-LAT', 'HR-SYR', 'HR-TAR', 'super-admin'],
            directPermissions: [config('recruitment.user_level_permissions.top_level_requester')],
        );
        $this->createUserWithRole(email: 'generaladmin@mail.com', password: $password, roleName: 'general-manager', employee_id: 2);
        $this->createUserWithRole(email: 'hrmanager@mail.com', password: $password, roleName: 'HR-SYR', employee_id: 3);
        $this->createUserWithRole(email: 'hr@mail.com', password: $password, roleName: 'HR-ALP', employee_id: 4);
        $this->createUserWithRole(email: 'manager@mail.com', password: $password, roleName: 'manager', employee_id: 5);
        $this->createUserWithRole(email: 'employee@mail.com', password: $password, roleName: 'employee', employee_id: 6);

        $this->command?->warn('Local seed password: '.$password);
    }

    private function createUserWithRole(
        string $email,
        string $password,
        string $roleName,
        $employee_id,
        array $extraRoles = [],
        array $directPermissions = [],
    ): void {
        $user = User::create([
            'employee_id' => $employee_id,
            'email' => $email,
            'password' => Hash::make($password),
        ]);

        $user->assignRole(array_values(array_unique(array_merge([$roleName], $extraRoles))));

        if ($directPermissions !== []) {
            $user->givePermissionTo(array_filter($directPermissions));
        }
    }
}
