<?php

namespace App\Traits;

use App\Models\Permission;
use Illuminate\Support\Facades\DB;

trait HasPermissions
{
    public function hasPermission(string $permissionKey): bool
    {
        return Permission::userHasPermission($this, $permissionKey);
    }

    /** @param string[] $permissionKeys */
    public function hasAnyPermission(array $permissionKeys): bool
    {
        foreach ($permissionKeys as $key) {
            if ($this->hasPermission($key)) {
                return true;
            }
        }

        return false;
    }

    /** @param string[] $permissionKeys */
    public function hasAllPermissions(array $permissionKeys): bool
    {
        foreach ($permissionKeys as $key) {
            if (! $this->hasPermission($key)) {
                return false;
            }
        }

        return true;
    }

    /** @return array<int, string> */
    public function getAvailablePermissions(): array
    {
        return Permission::getPermissionsForRole($this->role->value);
    }

    /** @param array<int, string> $permissions */
    public function syncPermissions(array $permissions): void
    {
        $roleName = $this->role->value;

        DB::transaction(function () use ($roleName, $permissions) {
            DB::table('role_permission')->where('role_name', $roleName)->delete();

            $rows = array_map(fn (string $key) => [
                'role_name' => $roleName,
                'permission_key' => $key,
                'created_at' => now(),
                'updated_at' => now(),
            ], $permissions);

            if (! empty($rows)) {
                DB::table('role_permission')->insert($rows);
            }
        });

        Permission::clearCache();
    }
}
