<?php

namespace App\Traits;

use App\Models\Permission;

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
            if (!$this->hasPermission($key)) {
                return false;
            }
        }
        return true;
    }
}
