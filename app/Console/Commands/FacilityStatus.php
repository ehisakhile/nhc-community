<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\Facility;
use App\Models\FacilityMembership;
use App\Modules\Enquiries\Mail\FacilityDecision;
use App\Modules\Staff\ProvisionRegisteredFacility;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Whoever runs this install approves, declines, suspends or reinstates a
 * facility. With no facility given, it lists the ones waiting. A facility that
 * registered itself on the website hears the decision by email.
 */
#[Signature('nhc:facility:status {facility? : The facility ID; leave out to list facilities waiting for approval} {status? : active, suspended or rejected} {--note= : Why, sent to the facility\'s admin}')]
#[Description('List facilities waiting for approval, or approve, decline, suspend or reinstate one')]
class FacilityStatus extends Command
{
    public function handle(ProvisionRegisteredFacility $provisioning): int
    {
        if (! $this->argument('facility')) {
            $rows = Facility::where('status', 'pending')->latest()->limit(200)->get()->map(function (Facility $f) {
                $admin = FacilityMembership::where('facility_id', $f->id)->where('role', 'admin')->with('user')->oldest()->first()?->user;

                return [$f->id, $f->name, $f->type, $admin?->email ?? '-', $f->created_at?->toDateTimeString()];
            });
            $rows->isEmpty()
                ? $this->info('No facilities are waiting for approval.')
                : $this->table(['ID', 'Name', 'Type', 'Admin', 'Registered'], $rows->all());

            return self::SUCCESS;
        }

        $facility = Facility::find($this->argument('facility'));
        $status = (string) $this->argument('status');
        if (! $facility) {
            $this->error('No facility with that ID.');

            return self::FAILURE;
        }
        if (! in_array($status, ['active', 'suspended', 'rejected'], true)) {
            $this->error('The status must be active, suspended or rejected.');

            return self::FAILURE;
        }

        if ($status === 'active' && $facility->registration_request) {
            $provisioning->ensureReady($facility);
        }
        $was = $facility->status;
        $facility->update(['status' => $status]);
        AuditLog::create([
            'actor_type' => 'system', 'facility_id' => $facility->id, 'action' => "facility.{$status}", 'occurred_at' => now(),
        ]);

        if ($was === 'pending') {
            $admin = FacilityMembership::where('facility_id', $facility->id)->where('role', 'admin')->with('user')->oldest()->first()?->user;
            if ($admin) {
                try {
                    Mail::to($admin->email)->send(new FacilityDecision($facility, $admin->name, $status === 'active', $this->option('note')));
                } catch (Throwable $e) {
                    report($e);
                }
            }
        }

        $this->info("{$facility->name} is now {$status}.");

        return self::SUCCESS;
    }
}
