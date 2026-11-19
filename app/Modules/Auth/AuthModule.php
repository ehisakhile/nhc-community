<?php

namespace App\Modules\Auth;

use App\Modules\Module;

/** Staff sign-in (email code or password) and patient sign-in through Hospify. */
class AuthModule extends Module
{
    public function key(): string
    {
        return 'auth';
    }
}
