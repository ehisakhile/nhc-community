<?php

namespace Tests\Feature\Auth;

use App\Models\Patient;
use App\Services\PatientIdentity;
use App\Support\Es256Jwt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/** "Link my NHC record" in the Citizen app, called by Hospify's API. */
class HospifyLinkingTest extends AuthTestCase
{
    private string $hospifyKey;

    private Patient $patient;

    protected function setUp(): void
    {
        parent::setUp();

        $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        openssl_pkey_export($key, $pem);
        $this->hospifyKey = $pem;
        config(['services.hospify.public_key' => openssl_pkey_get_details($key)['key']]);

        $this->patient = app(PatientIdentity::class)->registerAccountHolder('08062345678', ['first_name' => 'Ada', 'last_name' => 'Obi']);
        $this->patient->email = 'ada@example.com';
        $this->patient->save();
    }

    private function asHospify(string $scope): static
    {
        return $this->fresh()->withToken(Es256Jwt::sign([
            'iss' => 'hospify', 'aud' => 'nhc', 'scope' => $scope,
            'iat' => time(), 'exp' => time() + 300, 'jti' => (string) Str::ulid(),
        ], $this->hospifyKey, 'hospify-1'));
    }

    private function link(string $lookup, string $ref)
    {
        $challenge = $this->asHospify('links:start')
            ->postJson('/api/hospify/links/start', ['lookup' => $lookup, 'hospify_ref' => $ref])
            ->assertOk()->json('challenge');

        return $this->asHospify('links:confirm')->postJson('/api/hospify/links/confirm', [
            'challenge' => $challenge, 'code' => $this->smsCode(), 'hospify_ref' => $ref,
        ]);
    }

    public function test_linking_by_nhc_id_texts_the_phone_on_the_record(): void
    {
        $this->link('nhc-8062345678', 'ref_google')->assertOk()->assertJsonPath('nhc_id', 'NHC-8062345678');

        Http::assertSent(fn ($r) => $r['to'] === '2348062345678');
        $this->assertSame('ref_google', $this->patient->fresh()->hospify_ref);
    }

    public function test_linking_works_by_phone_whatever_email_the_hospify_account_has(): void
    {
        // e.g. an Apple account with a hidden relay email.
        $this->link('0806 234 5678', 'ref_apple_relay')->assertOk();
        $this->assertSame('ref_apple_relay', $this->patient->fresh()->hospify_ref);
    }

    public function test_suggest_finds_the_record_by_email_but_shows_only_a_masked_id(): void
    {
        $this->asHospify('links:suggest')->postJson('/api/hospify/links/suggest', ['email' => 'ADA@example.com'])
            ->assertOk()
            ->assertJsonPath('found', true)
            ->assertJsonPath('nhc_id_masked', 'NHC-806•••5678')
            ->assertJsonMissingPath('nhc_id');

        $this->asHospify('links:suggest')->postJson('/api/hospify/links/suggest', ['email' => 'nobody@example.com'])
            ->assertJsonPath('found', false);
    }

    public function test_a_wrong_code_links_nothing(): void
    {
        $challenge = $this->asHospify('links:start')
            ->postJson('/api/hospify/links/start', ['lookup' => 'NHC-8062345678', 'hospify_ref' => 'ref_a'])->json('challenge');

        $this->asHospify('links:confirm')->postJson('/api/hospify/links/confirm', [
            'challenge' => $challenge, 'code' => '000000', 'hospify_ref' => 'ref_a',
        ])->assertStatus(422);
        $this->assertNull($this->patient->fresh()->hospify_ref);
    }

    public function test_a_code_asked_for_one_account_cannot_link_another(): void
    {
        $challenge = $this->asHospify('links:start')
            ->postJson('/api/hospify/links/start', ['lookup' => 'NHC-8062345678', 'hospify_ref' => 'ref_a'])->json('challenge');

        $this->asHospify('links:confirm')->postJson('/api/hospify/links/confirm', [
            'challenge' => $challenge, 'code' => $this->smsCode(), 'hospify_ref' => 'ref_attacker',
        ])->assertStatus(422);
        $this->assertNull($this->patient->fresh()->hospify_ref);
    }

    public function test_an_unknown_record_gets_the_same_reply_and_no_text(): void
    {
        $reply = $this->asHospify('links:start')
            ->postJson('/api/hospify/links/start', ['lookup' => 'NHC-9099999999', 'hospify_ref' => 'ref_a'])
            ->assertOk()->json();

        $this->assertSame("If that matches an NHC record, we've texted a code to the phone on it.", $reply['message']);
        Http::assertNothingSent();
    }

    public function test_linking_from_a_second_account_moves_the_link(): void
    {
        $this->link('NHC-8062345678', 'ref_google')->assertOk();
        $this->link('NHC-8062345678', 'ref_apple')->assertOk()->assertJsonPath('previous_hospify_ref', 'ref_google');

        $this->assertSame('ref_apple', $this->patient->fresh()->hospify_ref);
    }

    public function test_one_hospify_account_cannot_hold_two_nhc_ids(): void
    {
        app(PatientIdentity::class)->registerAccountHolder('08031112222', ['first_name' => 'Bola', 'last_name' => 'Ade']);
        $this->link('NHC-8062345678', 'ref_shared')->assertOk();

        $this->link('NHC-8031112222', 'ref_shared')->assertStatus(409);
    }

    public function test_removing_a_link_ends_nhc_access(): void
    {
        $this->link('NHC-8062345678', 'ref_google')->assertOk();
        $this->patient->createToken('x', ['patient']);

        $this->asHospify('links:remove')->postJson('/api/hospify/links/remove', ['hospify_ref' => 'ref_google'])->assertNoContent();

        $this->assertNull($this->patient->fresh()->hospify_ref);
        $this->assertSame(0, $this->patient->tokens()->count());
    }

    public function test_link_endpoints_need_hospifys_signature_and_the_right_scope(): void
    {
        $this->postJson('/api/hospify/links/suggest', ['email' => 'ada@example.com'])->assertUnauthorized();
        $this->asHospify('links:suggest')->postJson('/api/hospify/links/start', ['lookup' => 'NHC-8062345678', 'hospify_ref' => 'x'])
            ->assertUnauthorized();
    }
}
