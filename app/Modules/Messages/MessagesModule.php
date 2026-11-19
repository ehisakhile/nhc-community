<?php

namespace App\Modules\Messages;

use App\Modules\Module;

/** Patients message a facility's care team from the Hospify app; staff answer in the portal. */
class MessagesModule extends Module
{
    public function key(): string
    {
        return 'messages';
    }

    public function features(): array
    {
        return [[
            'key' => 'messaging', 'name' => 'Messages', 'category' => 'Collaboration',
            'description' => 'Patients message your care team from the Hospify app; staff who can message reply from the portal.',
        ]];
    }
}
