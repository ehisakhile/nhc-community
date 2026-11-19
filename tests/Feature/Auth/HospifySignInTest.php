<?php

namespace Tests\Feature\Auth;

use App\Models\Patient;
use App\Services\PatientIdentity;
use App\Support\Es256Jwt;
use Illuminate\Support\Str;

class HospifySignInTest extends AuthTestCase
{
    private string $hospifyPrivate;

    private Patient $patient;

    protected function setUp(): void
    {
        parent::setUp();

        $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        openssl_pkey_export($key, $pem);
        $this->hospifyPrivate = $pem;
        config(['services.hospify.public_key' => openssl_pkey_get_details($key)['key']]);

        $this->patient = app(PatientIdentity::class)->registerAccountHolder('08062345678', ['first_name' => 'Ada', 'last_name' => 'Obi']);
        $this->patient->hospify_ref = 'hfr_test';
        $this->patient->save();
    }

    private function pass(array $overrides = [], ?string $key = null): string
    {
        return Es256Jwt::sign($overrides + [
            'iss' => 'hospify', 'aud' => 'nhc', 'sub' => 'hfr_test', 'nhc_id' => 'NHC-8062345678',
            'iat' => time(), 'exp' => time() + 300, 'jti' => (string) Str::ulid(),
        ], $key ?? $this->hospifyPrivate, 'hospify-1');
    }

    public function test_a_hospify_pass_signs_the_patient_in_without_another_login(): void
    {
        $token = $this->postJson('/api/auth/hospify', ['assertion' => $this->pass()])->assertOk()
            ->assertJsonPath('expires_in', 3600)->json('token');

        $this->withToken($token)->getJson('/api/patient/me')->assertOk()->assertJsonPath('nhc_id', 'NHC-8062345678');
    }

    public function test_a_pass_works_only_once(): void
    {
        $pass = $this->pass();
        $this->postJson('/api/auth/hospify', ['assertion' => $pass])->assertOk();
        $this->postJson('/api/auth/hospify', ['assertion' => $pass])->assertUnauthorized();
    }

    public function test_passes_that_are_forged_expired_or_for_someone_else_are_refused(): void
    {
        $other = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        openssl_pkey_export($other, $forger);

        $bad = [
            'signed by someone else' => $this->pass(key: $forger),
            'expired' => $this->pass(['iat' => time() - 900, 'exp' => time() - 600]),
            'too long-lived' => $this->pass(['exp' => time() + 86400]),
            'wrong audience' => $this->pass(['aud' => 'hospify']),
            'unlinked account' => $this->pass(['sub' => 'hfr_someone_else']),
            'another NHC ID' => $this->pass(['nhc_id' => 'NHC-8031112222']),
            'not a token' => 'abc.def.ghi',
        ];

        foreach ($bad as $why => $pass) {
            $this->postJson('/api/auth/hospify', ['assertion' => $pass])->assertUnauthorized();
        }
    }

    public function test_a_service_token_cannot_be_used_as_a_sign_in_pass(): void
    {
        $this->postJson('/api/auth/hospify', ['assertion' => $this->pass(['scope' => 'links:start'])])->assertUnauthorized();
    }

    public function test_an_unsigned_token_is_refused(): void
    {
        $b64 = fn ($v) => rtrim(strtr(base64_encode(json_encode($v)), '+/', '-_'), '=');
        $none = $b64(['alg' => 'none']).'.'.$b64(['iss' => 'hospify', 'aud' => 'nhc', 'sub' => 'hfr_test',
            'nhc_id' => 'NHC-8062345678', 'iat' => time(), 'exp' => time() + 60, 'jti' => 'x']).'.';

        $this->postJson('/api/auth/hospify', ['assertion' => $none])->assertUnauthorized();
    }

    public function test_patient_and_staff_tokens_cannot_be_swapped(): void
    {
        $token = $this->postJson('/api/auth/hospify', ['assertion' => $this->pass()])->json('token');
        $this->withToken($token)->getJson('/api/staff/me')->assertForbidden();

        $facility = $this->hospital();
        $staff = $this->staffToken($this->staff($facility, 'doctor', 'doc@example.com'));
        $this->fresh()->withToken($staff)->getJson('/api/patient/me')->assertForbidden();
    }
}
