<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Horsefly\User;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        // ── Create roles (idempotent) ─────────────────────────────────────────
        $roleNames = ['super_admin', 'admin', 'crm', 'sales', 'quality'];

        foreach ($roleNames as $name) {
            Role::firstOrCreate(
                ['name' => $name, 'guard_name' => 'web']
            );
        }

        // ── Grant super_admin every permission ───────────────────────────────
        $superAdminRole  = Role::where('name', 'super_admin')->first();
        $allPermissions  = Permission::all();

        if ($superAdminRole && $allPermissions->isNotEmpty()) {
            $superAdminRole->syncPermissions($allPermissions);
        }

        // ── Assign super_admin to user ID 1 ONLY on first-time setup ─────────
        // This seeder runs on every container start (entrypoint reconcile), so
        // we must NOT unconditionally syncRoles() here — that would strip and
        // revert user 1's roles back to super_admin on every deploy, silently
        // undoing any intentional role change. Only assign when user 1 has no
        // role yet (a fresh install).
        $user = User::find(1);
        if ($user && $user->roles()->count() === 0) {
            $user->assignRole('super_admin');
        }
    }
}
