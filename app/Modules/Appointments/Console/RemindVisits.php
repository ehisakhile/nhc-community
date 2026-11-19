<?php

namespace App\Modules\Appointments\Console;

use App\Modules\Appointments\Visits;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('nhc:appointments:remind')]
#[Description("Remind patients of tomorrow's visits (Hospify app push)")]
class RemindVisits extends Command
{
    public function handle(Visits $visits): int
    {
        $this->info($visits->remindTomorrow().' visit reminders sent.');

        return self::SUCCESS;
    }
}
