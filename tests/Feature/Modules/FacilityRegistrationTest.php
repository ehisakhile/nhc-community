<?php

namespace Tests\Feature\Modules;

use App\Models\Facility;
use App\Modules\Enquiries\Mail\FacilityDecision;
use App\Modules\Enquiries\Mail\FacilityRegistered;
use App\Modules\Enquiries\Mail\RegistrationCode;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Auth\AuthTestCase;

/** A pharmacy registers itself on the NHC website, signs in, and waits for Hospify to approve it. */
class FacilityRegistrationTest extends AuthTestCase
{
    private string $hospifyKey = '';

    private array $form = [
        'facility_name' => 'Ring Road Pharmacy', 'type' => 'pharmacy', 'state' => 'Edo', 'lga' => 'Oredo',
        'address' => '45 Ring Road, Benin City', 'phone' => '08031234567', 'admin_name' => 'Efe Osaro', 'email' => 'Efe@RingRoad.example',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        config(['nhc.enquiries_to' => 'team@nhc.example']);
        $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        openssl_pkey_export($key, $this->hospifyKey);
        config(['services.hospify.public_key' => openssl_pkey_get_details($key)['key']]);
    }

    protected function tearDown(): void
    {
        foreach (Facility::all() as $f) {
            $this->facilities[] = $f;
        }
        parent::tearDown();
    }

    private function register(): array
    {
        $challenge = $this->postJson('/api/facilities/register/start', $this->form)->assertOk()->json('challenge');
        $code = null;
        Mail::assertSent(RegistrationCode::class, function ($m) use (&$code) {
            $code = $m->code;

            return $m->hasTo('efe@ringroad.example');
        });
        $this->assertSame(0, Facility::count());  // nothing until the email is proven

        return $this->fresh()->postJson('/api/facilities/register/verify', ['challenge' => $challenge, 'code' => $code, 'device_name' => 'web'])
            ->assertCreated()->json();
    }

    public function test_registering_creates_a_pending_facility_and_signs_the_admin_in(): void
    {
        $r = $this->register();
        $facility = Facility::find($r['facility_id']);
        $this->assertSame(['pending', 'pharmacy'], [$facility->status, $facility->type]);
        Mail::assertSent(FacilityRegistered::class, fn ($m) => $m->hasTo('team@nhc.example'));

        $me = $this->fresh()->withToken($r['token'])->getJson('/api/staff/me')->assertOk()->json('hospitals.0');
        $this->assertSame(['pending', 'admin'], [$me['facility']['status'], $me['role']]);

    }

    public function test_registering_at_the_facility_gets_its_postcode_from_hospify(): void
    {
        config(['services.hospify.api_url' => 'https://hospify.test', 'services.hospify.events_secret' => 'shh']);
        Http::fake(['hospify.test/*' => function ($r) {
            $ok = hash_equals(hash_hmac('sha256', '6.335000,5.627000', 'shh'), $r->header('X-NHC-Signature')[0] ?? '');

            return Http::response($ok ? ['postcode' => 'ED12A3B45C6', 'display' => 'ED 12 A3 B4 5C6'] : [], $ok ? 200 : 401);
        }]);
        $this->form += ['latitude' => 6.335, 'longitude' => 5.627];
        $r = $this->register();
        $this->assertSame('ED12A3B45C6', $r['postcode']);
        $f = Facility::find($r['facility_id']);
        $this->assertSame(['ED12A3B45C6', '45 Ring Road, Benin City'], [$f->postcode, $f->address]);
    }

    public function test_a_wrong_code_creates_nothing(): void
    {
        $challenge = $this->postJson('/api/facilities/register/start', $this->form)->json('challenge');
        $this->fresh()->postJson('/api/facilities/register/verify', ['challenge' => $challenge, 'code' => '000000', 'device_name' => 'web'])->assertUnprocessable();
        $this->assertSame(0, Facility::count());
    }

    public function test_the_operator_approves_it_and_the_admin_hears(): void
    {
        $id = $this->register()['facility_id'];
        $this->artisan('nhc:facility:status')->expectsOutputToContain($id)->assertSuccessful();

        $this->artisan('nhc:facility:status', ['facility' => $id, 'status' => 'active'])->assertSuccessful();
        $this->assertSame('active', Facility::find($id)->status);
        Mail::assertSent(FacilityDecision::class, fn ($m) => $m->approved && $m->hasTo('efe@ringroad.example'));
    }

    public function test_declining_says_why(): void
    {
        $id = $this->register()['facility_id'];
        $this->artisan('nhc:facility:status', ['facility' => $id, 'status' => 'rejected', '--note' => 'We could not confirm your PCN licence.'])->assertSuccessful();
        Mail::assertSent(FacilityDecision::class, fn ($m) => ! $m->approved && $m->note === 'We could not confirm your PCN licence.');
    }

    public function test_a_bad_status_changes_nothing(): void
    {
        $id = $this->register()['facility_id'];
        $this->artisan('nhc:facility:status', ['facility' => $id, 'status' => 'deleted'])->assertFailed();
        $this->assertSame('pending', Facility::find($id)->status);
    }
}
