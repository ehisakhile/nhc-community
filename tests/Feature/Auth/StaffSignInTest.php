<?php

namespace Tests\Feature\Auth;

use App\Mail\StaffInvite;
use App\Models\Facility;
use App\Models\FacilityMembership;
use App\Models\OneTimeCode;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

class StaffSignInTest extends AuthTestCase
{
    public function test_staff_sign_in_by_email_code_and_get_their_role_and_dashboard(): void
    {
        $facility = $this->hospital();
        $user = User::create(['name' => 'Dr Bello', 'email' => 'bello@example.com']);
        FacilityMembership::create(['user_id' => $user->id, 'facility_id' => $facility->id, 'role' => 'doctor', 'status' => 'invited']);

        $challenge = $this->postJson('/api/auth/staff/code', ['email' => 'Bello@example.com'])->assertOk()->json('challenge');
        $token = $this->postJson('/api/auth/staff/verify', [
            'challenge' => $challenge, 'code' => $this->emailCode('bello@example.com'), 'device_name' => 'Front desk PC',
        ])->assertOk()->json('token');

        $this->withToken($token)->getJson('/api/staff/me')->assertOk()
            ->assertJsonPath('hospitals.0.role', 'doctor')
            ->assertJsonPath('hospitals.0.dashboard', 'clinical');
    }

    public function test_an_unknown_staff_email_gets_the_same_reply_and_no_email(): void
    {
        $this->postJson('/api/auth/staff/code', ['email' => 'nobody@example.com'])->assertOk()->assertJsonStructure(['challenge']);
        Mail::assertNothingSent();
    }

    public function test_a_code_works_once_and_only_five_guesses_are_allowed(): void
    {
        User::create(['name' => 'Dr Bello', 'email' => 'bello@example.com']);
        $challenge = $this->postJson('/api/auth/staff/code', ['email' => 'bello@example.com'])->json('challenge');
        $code = $this->emailCode('bello@example.com');
        $wrong = $code === '999999' ? '999998' : '999999';

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/auth/staff/verify', ['challenge' => $challenge, 'code' => $wrong, 'device_name' => 'x'])->assertStatus(422);
        }

        // The right code is now refused too: the challenge is spent.
        $this->postJson('/api/auth/staff/verify', ['challenge' => $challenge, 'code' => $code, 'device_name' => 'x'])->assertStatus(422);
        $this->assertStringNotContainsString($code, OneTimeCode::first()->getRawOriginal('code_hash'));
    }

    public function test_only_a_hospital_admin_can_invite_staff(): void
    {
        $facility = $this->hospital();
        $admin = $this->staff($facility, 'admin', 'admin@example.com');
        $nurse = $this->staff($facility, 'nurse', 'nurse@example.com');
        $invite = ['email' => 'doc@example.com', 'name' => 'Dr Doc', 'role' => 'doctor'];

        $this->withToken($this->staffToken($nurse))
            ->postJson("/api/staff/facilities/{$facility->id}/invites", $invite)->assertForbidden();

        $this->fresh()->withToken($this->staffToken($admin))
            ->postJson("/api/staff/facilities/{$facility->id}/invites", $invite)->assertCreated();
        Mail::assertSent(StaffInvite::class, fn ($m) => $m->hasTo('doc@example.com') && $m->role === 'Doctor');
    }

    public function test_an_invite_must_name_a_known_role(): void
    {
        $facility = $this->hospital();
        $admin = $this->staff($facility, 'admin', 'admin@example.com');

        $this->withToken($this->staffToken($admin))->postJson("/api/staff/facilities/{$facility->id}/invites", [
            'email' => 'x@example.com', 'name' => 'X', 'role' => 'superuser',
        ])->assertStatus(422);
    }

    public function test_one_person_can_hold_different_roles_at_different_hospitals(): void
    {
        $a = $this->hospital('Hospital A');
        $b = $this->hospital('Hospital B');
        $user = $this->staff($a, 'doctor', 'ada@example.com');
        FacilityMembership::create(['user_id' => $user->id, 'facility_id' => $b->id, 'role' => 'admin', 'status' => 'active']);

        $hospitals = collect($this->withToken($this->staffToken($user))->getJson('/api/staff/me')->json('hospitals'));

        $this->assertEqualsCanonicalizing(['clinical', 'admin'], $hospitals->pluck('dashboard')->all());
    }

    public function test_demo_staff_can_sign_in_with_a_password(): void
    {
        $this->artisan('nhc:demo', ['--email' => 'demo@example.com', '--password' => 'Demo-pass-2026'])->assertSuccessful();
        $this->facilities[] = Facility::sole();

        $token = $this->postJson('/api/auth/staff/password', [
            'email' => 'Demo+Doctor@example.com', 'password' => 'Demo-pass-2026', 'device_name' => 'web',
        ])->assertOk()->json('token');

        $this->withToken($token)->getJson('/api/staff/me')->assertOk()->assertJsonPath('hospitals.0.role', 'doctor');
        $this->assertNotSame('Demo-pass-2026', User::where('email', 'demo+doctor@example.com')->value('password'));
    }

    public function test_a_wrong_password_and_accounts_without_one_get_the_same_answer(): void
    {
        User::create(['name' => 'Invited', 'email' => 'invited@example.com']);  // no password: codes only
        User::create(['name' => 'Demo', 'email' => 'demo@example.com', 'password' => 'Right-pass-2026']);

        foreach (['invited@example.com', 'demo@example.com', 'nobody@example.com'] as $email) {
            $this->postJson('/api/auth/staff/password', ['email' => $email, 'password' => 'guess', 'device_name' => 'x'])
                ->assertStatus(422)->assertJsonPath('message', 'Wrong email or password.');
        }
    }

    public function test_five_wrong_passwords_lock_the_email(): void
    {
        User::create(['name' => 'Demo', 'email' => 'demo@example.com', 'password' => 'Right-pass-2026']);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/auth/staff/password', ['email' => 'demo@example.com', 'password' => 'guess', 'device_name' => 'x']);
        }

        $this->postJson('/api/auth/staff/password', ['email' => 'demo@example.com', 'password' => 'Right-pass-2026', 'device_name' => 'x'])
            ->assertStatus(429);
    }

    public function test_demo_passwords_can_be_removed(): void
    {
        $this->artisan('nhc:demo', ['--email' => 'demo@example.com', '--password' => 'Demo-pass-2026'])->assertSuccessful();
        $this->facilities[] = Facility::sole();
        $this->artisan('nhc:demo', ['--email' => 'demo@example.com', '--clear-passwords' => true])->assertSuccessful();

        $this->postJson('/api/auth/staff/password', ['email' => 'demo@example.com', 'password' => 'Demo-pass-2026', 'device_name' => 'x'])
            ->assertStatus(422);
    }

    public function test_the_demo_password_can_be_typed_at_a_hidden_prompt(): void
    {
        $this->artisan('nhc:demo', ['--email' => 'demo@example.com'])
            ->expectsQuestion('Password for every demo account (10+ characters; press Enter to use email codes instead)', 'Typed-in-2026')
            ->expectsQuestion('Type it again', 'Typed-in-2026')
            ->assertSuccessful();
        $this->facilities[] = Facility::sole();

        $this->postJson('/api/auth/staff/password', ['email' => 'demo+nurse@example.com', 'password' => 'Typed-in-2026', 'device_name' => 'web'])
            ->assertOk();
    }

    public function test_a_mistyped_demo_password_changes_nothing(): void
    {
        $this->artisan('nhc:demo', ['--email' => 'demo@example.com'])
            ->expectsQuestion('Password for every demo account (10+ characters; press Enter to use email codes instead)', 'Typed-in-2026')
            ->expectsQuestion('Type it again', 'Typed-in-2027')
            ->assertFailed();
        $this->assertSame(0, Facility::count());
    }

    public function test_demo_passwords_must_be_long_enough(): void
    {
        $this->artisan('nhc:demo', ['--email' => 'demo@example.com', '--password' => 'short'])->assertFailed();
    }
}
