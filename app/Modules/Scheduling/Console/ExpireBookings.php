<?php

namespace App\Modules\Scheduling\Console;

use App\Modules\Appointments\Visits;
use App\Modules\Scheduling\Scheduling;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('nhc:schedule:expire')]
#[Description('Every 5 minutes: drop old holds, expire visit requests the facility never confirmed')]
class ExpireBookings extends Command
{
    public function handle(Scheduling $scheduling, Visits $visits): int
    {
        $holds = $scheduling->dropExpiredHolds();
        $v = $visits->expireUnconfirmed();
        $this->info("{$holds} holds dropped, {$v} visits expired.");

        return self::SUCCESS;
    }
}
