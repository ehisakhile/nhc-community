<?php

namespace App\Console\Commands;

use App\Mail\StaffInvite;
use App\Models\AuditLog;
use App\Models\Facility;
use App\Models\FacilityMembership;
use App\Models\User;
use App\Support\FacilityTypes;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

#[Signature('nhc:facility {name} {--type=hospital} {--state=} {--lga=} {--admin-email=} {--admin-name=} {--active}')]
#[Description('Add a hospital (creates its own database) and invite its first admin')]
class CreateFacility extends Command
{
    public function handle(): int
    {
        $this->callSilently('nhc:features:sync');  // so the new hospital has a feature catalogue

        $email = Str::lower(trim((string) $this->option('admin-email')));
        if (! filter_var($email, FILTER_VALIDATE_EMAIL) || blank($this->option('admin-name'))) {
            $this->error('Give --admin-email and --admin-name for the hospital\'s first admin.');

            return self::FAILURE;
        }

        if (! isset(FacilityTypes::all()[$this->option('type')])) {
            $this->error('--type must be one of: '.implode(', ', array_keys(FacilityTypes::all())));

            return self::FAILURE;
        }

        $facility = Facility::create([
            'name' => $this->argument('name'),
            'type' => $this->option('type'),
            'state' => $this->option('state'),
            'lga' => $this->option('lga'),
            'status' => $this->option('active') ? 'active' : 'pending',
        ]);

        $admin = User::firstOrCreate(['email' => $email], ['name' => $this->option('admin-name')]);
        FacilityMembership::updateOrCreate(
            ['user_id' => $admin->id, 'facility_id' => $facility->id],
            ['role' => 'admin', 'status' => 'invited'],
        );
        Mail::to($admin->email)->send(new StaffInvite($admin->name, $facility->name, config('nhc.roles.admin.label')));

        AuditLog::create([
            'actor_type' => 'system', 'facility_id' => $facility->id, 'action' => 'facility.created',
            'target_type' => 'user', 'target_id' => $admin->id, 'occurred_at' => now(),
        ]);

        $this->info("Added {$facility->name} ({$facility->id}), status {$facility->status}. Invite sent to {$admin->email}.");

        return self::SUCCESS;
    }
}
