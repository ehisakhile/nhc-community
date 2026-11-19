<?php

namespace App\Modules\RecordSharing;

use App\Modules\Module;

/** Hospitals asking each other for a patient's records, with the patient's consent. */
class RecordSharingModule extends Module
{
    public function key(): string
    {
        return 'record_sharing';
    }

    public function features(): array
    {
        return [[
            'key' => 'record_sharing', 'name' => 'Record sharing', 'category' => 'Clinical', 'default_on' => true,
            'description' => 'Ask other NHC hospitals for a patient\'s records, and release yours when asked. The patient approves every request; you choose view-for-a-time or a copy.',
        ]];
    }
}
