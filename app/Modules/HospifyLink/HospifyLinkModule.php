<?php

namespace App\Modules\HospifyLink;

use App\Modules\Module;

/** The Hospify Citizen app: linking accounts to NHC records, and the patient's own view. */
class HospifyLinkModule extends Module
{
    public function key(): string
    {
        return 'hospify_link';
    }
}
