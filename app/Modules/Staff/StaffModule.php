<?php

namespace App\Modules\Staff;

use App\Modules\Module;

/** Staff profiles, roles and invitations. */
class StaffModule extends Module
{
    public function key(): string
    {
        return 'staff';
    }

    public function features(): array
    {
        return [[
            'key' => 'staff_management', 'name' => 'Staff and roles', 'category' => 'Administration', 'core' => true,
            'description' => 'Invite staff by email. Their role decides their dashboard and what they can do.',
        ]];
    }
}
