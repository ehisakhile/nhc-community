<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Support\Es256Jwt;
use Illuminate\Support\Str;

/** Hospify Pro sign-in with an NHC staff account (Hospify's API asks). */
class HospifyStaffLookupTest extends AuthTestCase
{
    private string $hospifyKey;

    protected function setUp(): void
    {
        parent::setUp();
        $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        openssl_pkey_export($key, $pem);
        $this->hospifyKey = $pem;
        config(['services.hospify.public_key' => openssl_pkey_get_details($key)['key']]);
    }

    private function asHospify(string $scope = 'staff:lookup'): static
    {
        return $this->fresh()->withToken(Es256Jwt::sign([
            'iss' => 'hospify', 'aud' => 'nhc', 'scope' => $scope,
            'iat' => time(), 'exp' => time() + 300, 'jti' => (string) Str::ulid(),
        ], $this->hospifyKey, 'hospify-1'));
    }

    private function lookup(array $body, string $scope = 'staff:lookup')
    {
        return $this->asHospify($scope)->postJson('/api/hospify/staff/lookup', $body);
    }

    public function test_it_says_whether_an_email_is_staff_and_gives_their_name(): void
    {
        $u = User::create(['name' => 'Dr Bola Ade', 'email' => 'bola@clinic.ng']);
        $u->email_verified_at = now();
        $u->save();

        $this->lookup(['email' => ' BOLA@clinic.ng '])->assertOk()
            ->assertExactJson(['staff' => true, 'name' => 'Dr Bola Ade', 'email_verified' => true]);
        $this->lookup(['email' => 'nobody@clinic.ng'])->assertOk()->assertExactJson(['staff' => false]);
    }

    public function test_deleted_staff_are_not_staff(): void
    {
        User::create(['name' => 'Gone', 'email' => 'gone@clinic.ng'])->delete();
        $this->lookup(['email' => 'gone@clinic.ng'])->assertOk()->assertExactJson(['staff' => false]);
    }

    public function test_with_a_password_it_checks_the_nhc_password_and_limits_guesses(): void
    {
        User::create(['name' => 'Demo Nurse', 'email' => 'nurse@demo.ng', 'password' => 'right-password-1']);
        User::create(['name' => 'Code Only', 'email' => 'code@clinic.ng']);

        $this->lookup(['email' => 'nurse@demo.ng', 'password' => 'right-password-1'])->assertOk()
            ->assertJson(['staff' => true, 'name' => 'Demo Nurse', 'email_verified' => false]);
        $this->lookup(['email' => 'code@clinic.ng', 'password' => 'anything'])->assertOk()->assertExactJson(['staff' => false]);

        for ($i = 0; $i < 5; $i++) {
            $this->lookup(['email' => 'nurse@demo.ng', 'password' => 'wrong'])->assertOk()->assertExactJson(['staff' => false]);
        }
        $this->lookup(['email' => 'nurse@demo.ng', 'password' => 'right-password-1'])->assertStatus(429);
    }

    public function test_it_needs_hospifys_signature_and_this_scope(): void
    {
        $this->postJson('/api/hospify/staff/lookup', ['email' => 'a@b.ng'])->assertUnauthorized();
        $this->lookup(['email' => 'a@b.ng'], 'links:suggest')->assertUnauthorized();
    }
}
