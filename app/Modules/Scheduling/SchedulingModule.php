<?php

namespace App\Modules\Scheduling;

use App\Modules\Module;
use App\Modules\Scheduling\Console\ExpireBookings;

/** Bookable hours: when a facility takes visits and care bookings, and which times are still free. */
class SchedulingModule extends Module
{
    public function key(): string
    {
        return 'scheduling';
    }

    public function commands(): array
    {
        return [ExpireBookings::class];
    }
}
