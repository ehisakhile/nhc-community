<?php

namespace App\Modules\Enquiries;

use App\Modules\Module;

/** Hospitals asking to join NHC from the website. No hospital feature: it isn't per hospital. */
class EnquiriesModule extends Module
{
    public function key(): string
    {
        return 'enquiries';
    }
}
