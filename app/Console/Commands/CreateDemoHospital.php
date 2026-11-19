<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\Facility;
use App\Models\FacilityMembership;
use App\Models\User;
use App\Support\FacilityTypes;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * A live demo hospital with one member of staff in every role. Staff emails
 * are plus-aliases of yours (you+doctor@gmail.com), so every sign-in code
 * reaches your inbox and you can try each role's dashboard. Safe to re-run.
 */
#[Signature('nhc:demo {--email= : Your email; you become the admin and the aliases are built from it} {--name=NHC Demo Hospital} {--password= : One password for every demo account (or leave it out and type it at the hidden prompt)} {--clear-passwords : Remove the demo accounts\' passwords (back to email codes only)} {--type=hospital : hospital, clinic, primary_health_centre, dental_clinic...} {--pending : Waiting for approval, as if it registered itself (approve it with nhc:facility:status)}')]
#[Description('Create a demo facility of any type (--type=pharmacy...) with staff in each of its roles, all signing in through your inbox')]
class CreateDemoHospital extends Command
{
    private const LICENCES = ['MDCN' => 'MDCN/DEMO/', 'NMCN' => 'RN/DEMO/', 'PCN' => 'PCN/DEMO/', 'MLSCN' => 'MLSCN/DEMO/', 'RRBN' => 'RRBN/DEMO/', 'ODORBN' => 'ODORBN/DEMO/', 'MRTB' => 'MRTB/DEMO/'];

    public function handle(): int
    {
        $this->callSilently('nhc:features:sync');  // so the new hospital has a feature catalogue

        $email = Str::lower(trim((string) $this->option('email')));
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('Give --email=you@example.com. You become the admin; the other roles use you+role@… aliases.');

            return self::FAILURE;
        }
        [$local, $domain] = explode('@', $email, 2);
        $type = $this->option('type');
        if (! isset(FacilityTypes::all()[$type])) {
            $this->error('--type must be one of: '.implode(', ', array_keys(FacilityTypes::all())));

            return self::FAILURE;
        }

        // Typed at a hidden prompt, so it never lands in the shell history. Empty: email codes only.
        $password = $this->option('password');
        if ($password === null && ! $this->option('clear-passwords') && $this->input->isInteractive()) {
            $password = $this->secret('Password for every demo account (10+ characters; press Enter to use email codes instead)') ?: null;
            if ($password !== null && $this->secret('Type it again') !== $password) {
                $this->error("The two passwords don't match. Nothing was changed.");

                return self::FAILURE;
            }
        }
        if ($password !== null && Str::length($password) < 10) {
            $this->error('Use a password of at least 10 characters. Nothing was changed.');

            return self::FAILURE;
        }

        $facility = Facility::where('name', $this->option('name'))->first() ?? Facility::create([
            'name' => $this->option('name'),
            'type' => $type,
            'status' => $this->option('pending') ? 'pending' : 'active',
            'email' => $email,
            'phone' => '08000000000',
            // The pharmacy demo is in Benin City, where the first partners are.
            'state' => $type === 'pharmacy' ? 'Edo' : 'Lagos',
            'lga' => $type === 'pharmacy' ? 'Oredo' : 'Ikeja',
        ]);
        if ($facility->status !== 'active' && ! $this->option('pending')) {
            $facility->update(['status' => 'active']);
        }

        $rows = [];
        $n = 0;
        foreach (FacilityTypes::roles($type) as $role) {
            $spec = config("nhc.roles.{$role}");
            $n++;
            $address = $role === 'admin' ? $email : "{$local}+".str_replace('_', '', $role)."@{$domain}";
            $user = User::firstOrCreate(['email' => $address], ['name' => 'Demo '.$spec['label']]);
            if ($this->option('clear-passwords')) {
                $user->forceFill(['password' => null])->save();
            } elseif ($password !== null) {
                $user->forceFill(['password' => $password])->save();  // hashed by the model's cast
            }

            $licence = isset($spec['licence']) ? self::LICENCES[$spec['licence']].str_pad((string) $n, 4, '0', STR_PAD_LEFT) : null;
            FacilityMembership::updateOrCreate(
                ['user_id' => $user->id, 'facility_id' => $facility->id],
                ['role' => $role, 'status' => 'active', 'licence_number' => $licence, 'licence_checked_at' => $licence ? now() : null],
            );

            $rows[] = [$spec['label'], $address, $spec['dashboard']];
        }

        AuditLog::create([
            'actor_type' => 'system', 'facility_id' => $facility->id, 'action' => 'facility.demo_seeded', 'occurred_at' => now(),
        ]);

        $this->info($facility->status === 'active'
            ? "{$facility->name} ({$facility->id}) is live, with staff in every role:"
            : "{$facility->name} ({$facility->id}) is waiting for approval (php artisan nhc:facility:status {$facility->id} active), with staff in every role:");
        $this->table(['Role', 'Sign in with', 'Dashboard'], $rows);
        $this->line(match (true) {
            (bool) $this->option('clear-passwords') => 'Passwords removed: these accounts sign in with email codes only.',
            $password !== null => 'Sign in on the NHC web app with any of these and the password you chose, or with an email code.',
            default => 'Sign in on the NHC web app with any of these; the code arrives in your inbox. Add --password=… for password sign-in.',
        });

        return self::SUCCESS;
    }
}
