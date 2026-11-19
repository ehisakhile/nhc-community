<?php

namespace App\Modules\Appointments;

use App\Modules\Appointments\Console\RemindVisits;
use App\Modules\Module;

/** Patients ask for visits in the Hospify app; the front desk confirms, checks in and closes them. */
class AppointmentsModule extends Module
{
    public function key(): string
    {
        return 'appointments';
    }

    public function features(): array
    {
        return [[
            'key' => 'appointments', 'name' => 'Appointments', 'category' => 'Front desk',
            'description' => 'Patients ask for visits from the Hospify app; the front desk confirms a time, checks them in, and they get reminders.',
        ]];
    }

    public function commands(): array
    {
        return [RemindVisits::class];
    }
}
