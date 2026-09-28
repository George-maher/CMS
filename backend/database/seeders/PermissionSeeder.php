<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\Permission;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = Permission::defaultPermissions();
        $rolePermissions = Permission::defaultRolePermissions();

        DB::transaction(function () use ($permissions, $rolePermissions) {
            foreach ($permissions as $perm) {
                Permission::updateOrCreate(
                    ['key' => $perm['key']],
                    $perm
                );
            }

            DB::table('role_permission')->truncate();

            $now = now();
            $pivotData = [];
            foreach ($rolePermissions as $roleName => $permKeys) {
                foreach ($permKeys as $permKey) {
                    $pivotData[] = [
                        'role_name' => $roleName,
                        'permission_key' => $permKey,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }

            DB::table('role_permission')->insert($pivotData);
        });

        Permission::clearCache();
    }
}
