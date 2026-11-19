<?php

namespace Tests\Feature\Auth;

use App\Models\AuditLog;
use App\Models\FacilityMembership;
use App\Models\User;
use App\Support\Es256Jwt;
use Illuminate\Support\Str;

class HospifyStaffSignInTest extends AuthTestCase
{
    private string $privateKey = '';

    protected function setUp(): void
    {
        parent::setUp();
        $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        openssl_pkey_export($key, $this->privateKey);
        config(['services.hospify.public_key' => openssl_pkey_get_details($key)['key']]);
    }

    private function pass(array $overrides = [], ?string $key = null): string
    {
        return Es256Jwt::sign($overrides + [
            'iss' => 'hospify', 'aud' => 'nhc', 'scope' => 'staff:sign-in',
            'sub' => 'hfr_staff', 'email' => 'ada@example.com', 'email_verified' => true, 'name' => 'Ada Obi',
            'iat' => time(), 'exp' => time() + 300, 'jti' => (string) Str::ulid(),
        ], $key ?? $this->privateKey, 'hospify-1');
    }

    public function test_existing_staff_sign_in_with_the_same_roles_without_a_second_code(): void
    {
        $facility = $this->hospital();
        $user = $this->staff($facility, 'doctor', 'ada@example.com');
        $token = $this->postJson('/api/auth/staff/hospify', ['assertion' => $this->pass(), 'device_name' => 'Hospify Pro'])
            ->assertOk()->assertJsonPath('expires_in', 43200)->json('token');
        $this->withToken($token)->getJson('/api/staff/me')->assertOk()
            ->assertJsonPath('id', $user->id)->assertJsonPath('hospitals.0.role', 'doctor');
        $this->assertTrue(AuditLog::where('actor_id', $user->id)->where('action', 'staff.sign_in_hospify')->exists());
        $this->assertSame(1, User::where('email', 'ada@example.com')->count());
    }

    public function test_new_verified_identity_gets_no_facility_memberships_or_admin_privileges(): void
    {
        $token = $this->postJson('/api/auth/staff/hospify', ['assertion' => $this->pass()])->assertOk()->json('token');
        $this->withToken($token)->getJson('/api/staff/me')->assertOk()->assertJsonPath('hospitals', []);
        $user = User::where('email', 'ada@example.com')->firstOrFail();
        $this->assertNotNull($user->email_verified_at);
        $this->assertFalse($user->is_super_admin);
        $this->withToken($token)->getJson('/api/patient/me')->assertForbidden();
    }

    public function test_only_existing_invitations_are_activated(): void
    {
        $facility = $this->hospital();
        $user = User::create(['name' => 'Ada', 'email' => 'ada@example.com']);
        $membership = FacilityMembership::create(['user_id' => $user->id, 'facility_id' => $facility->id, 'role' => 'nurse', 'status' => 'invited']);
        $this->postJson('/api/auth/staff/hospify', ['assertion' => $this->pass()])->assertOk();
        $this->assertSame('active', $membership->fresh()->status);
        $this->assertSame('nurse', $membership->fresh()->role);
    }

    public function test_pass_is_one_use_and_staff_sign_out_revokes_its_token(): void
    {
        $assertion = $this->pass();
        $token = $this->postJson('/api/auth/staff/hospify', ['assertion' => $assertion])->assertOk()->json('token');
        $this->postJson('/api/auth/staff/hospify', ['assertion' => $assertion])->assertUnauthorized();
        $this->withToken($token)->postJson('/api/staff/sign-out')->assertNoContent();
        $this->fresh()->withToken($token)->getJson('/api/staff/me')->assertUnauthorized();
    }

    public function test_wrong_scope_unverified_email_and_invalid_tokens_cannot_create_staff_sessions(): void
    {
        $other = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        openssl_pkey_export($other, $forgedKey);
        foreach ([
            $this->pass(['scope' => 'shop:read']),
            $this->pass(['scope' => null, 'nhc_id' => 'NHC-8062345678']),
            $this->pass(['email_verified' => false]),
            $this->pass(['email_verified' => 'true']),
            $this->pass(['email' => 'not-an-email']),
            $this->pass(['email' => 'ada@privaterelay.appleid.com']),
            $this->pass(['sub' => '']),
            $this->pass(['aud' => 'elsewhere']),
            $this->pass(['iat' => time() - 900, 'exp' => time() - 600]),
            $this->pass(['exp' => time() + 86400]),
            $this->pass(key: $forgedKey),
        ] as $assertion) {
            $this->postJson('/api/auth/staff/hospify', ['assertion' => $assertion])->assertUnauthorized();
        }
        $this->assertSame(0, User::count());
    }

    public function test_staff_pass_cannot_sign_in_as_a_patient_and_deleted_staff_stay_deleted(): void
    {
        $this->postJson('/api/auth/hospify', ['assertion' => $this->pass()])->assertUnauthorized();
        $user = User::create(['name' => 'Ada', 'email' => 'ada@example.com']);
        $user->delete();
        $this->postJson('/api/auth/staff/hospify', ['assertion' => $this->pass()])->assertForbidden();
        $this->assertTrue($user->fresh()->trashed());
    }
}
