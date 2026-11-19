<?php

namespace Tests\Feature;

use App\Models\Facility;
use App\Models\User;
use App\Support\FacilityTypes;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Stancl\Tenancy\Events\DatabaseCreated;
use Tests\Feature\Auth\AuthTestCase;

class StaffFacilityRegistrationTest extends AuthTestCase
{
    private function details(array $overrides = []): array
    {
        return array_replace([
            'request_id' => (string) Str::uuid(), 'name' => 'Ada Practice', 'type' => 'solo',
            'state' => 'Lagos', 'lga' => 'Ikeja', 'address' => '10 Medical Road', 'phone' => '+2348012345678',
        ], $overrides);
    }

    public function test_only_staff_can_create_a_workspace(): void
    {
        $this->postJson('/api/staff/facilities', $this->details())->assertUnauthorized();
        $user = User::create(['name' => 'Citizen', 'email' => 'citizen@example.test']);
        $this->fresh()->withToken($user->createToken('patient', ['patient'])->plainTextToken)
            ->postJson('/api/staff/facilities', $this->details())->assertForbidden();
        $this->assertDatabaseCount('tenants', 0);
    }

    public function test_every_type_creates_a_pending_facility_with_only_its_creator_as_admin(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $user = User::create(['name' => 'Ada', 'email' => 'ada@example.test']);
        $this->withToken($this->staffToken($user));
        foreach (array_keys(FacilityTypes::all()) as $type) {
            $response = $this->postJson('/api/staff/facilities', $this->details([
                'type' => $type, 'status' => 'active', 'role' => 'doctor', 'user_id' => 999,
            ]))->assertCreated()->assertJsonPath('status', 'pending')->assertJsonPath('type', $type);
            $facility = Facility::findOrFail($response->json('facility_id'));
            $this->facilities[] = $facility;
            $this->assertDatabaseHas('facility_memberships', ['facility_id' => $facility->id, 'user_id' => $user->id, 'role' => 'admin', 'status' => 'active']);
            $this->assertSame($user->email, $facility->email);
            $this->assertSame('Ikeja', $facility->lga);
            $this->assertDatabaseHas('facility_memberships', [
                'facility_id' => $facility->id, 'user_id' => $user->id, 'role' => 'admin', 'status' => 'active',
            ]);
            $this->assertSame(1, $facility->memberships()->count());
        }
        $this->getJson('/api/staff/me')->assertOk()->assertJsonCount(count(FacilityTypes::all()), 'hospitals');
        $this->getJson('/api/staff/facilities/'.$facility->id.'/patients')->assertUnprocessable();
    }

    public function test_validation_creates_nothing(): void
    {
        $user = User::create(['name' => 'Ada', 'email' => 'ada@example.test']);
        $this->withToken($this->staffToken($user));
        $this->postJson('/api/staff/facilities', [])->assertUnprocessable()
            ->assertJsonValidationErrors(['request_id', 'name', 'type', 'state', 'lga', 'address', 'phone']);
        foreach (['type' => 'unknown', 'phone' => '-------', 'name' => ' ', 'state' => ' ', 'logo' => 'invalid'] as $field => $value) {
            $this->postJson('/api/staff/facilities', $this->details([$field => $value]))->assertUnprocessable();
        }
        $this->assertDatabaseCount('tenants', 0);
    }

    public function test_retry_is_idempotent_and_scoped_to_the_authenticated_creator(): void
    {
        $user = User::create(['name' => 'Ada', 'email' => 'ada@example.test']);
        $body = $this->details();
        $first = $this->withToken($this->staffToken($user))->postJson('/api/staff/facilities', $body)->assertCreated();
        $this->facilities[] = Facility::findOrFail($first->json('facility_id'));
        $this->postJson('/api/staff/facilities', $body)->assertOk()->assertJsonPath('facility_id', $first->json('facility_id'));
        $this->assertDatabaseCount('tenants', 1);
        $this->assertDatabaseCount('audit_log', 1);
        $other = User::create(['name' => 'Other', 'email' => 'other@example.test']);
        $second = $this->fresh()->withToken($this->staffToken($other))->postJson('/api/staff/facilities', $body)->assertCreated();
        $this->facilities[] = Facility::findOrFail($second->json('facility_id'));
        $this->assertNotSame($first->json('facility_id'), $second->json('facility_id'));
    }

    public function test_logos_are_validated_stored_and_returned_in_the_profile(): void
    {
        $disk = Storage::fake('public');
        $user = User::create(['name' => 'Ada', 'email' => 'ada@example.test']);
        $this->withToken($this->staffToken($user));
        foreach ([base64_encode('<svg></svg>'), base64_encode(str_repeat('a', 2097153))] as $logo) {
            $this->postJson('/api/staff/facilities', $this->details(['logo' => $logo]))->assertUnprocessable();
        }
        $this->assertDatabaseCount('tenants', 0);
        $image = UploadedFile::fake()->image('logo.png', 50, 50);
        $response = $this->postJson('/api/staff/facilities', $this->details(['logo' => base64_encode($image->getContent())]))->assertCreated();
        $facility = Facility::findOrFail($response->json('facility_id'));
        $this->facilities[] = $facility;
        $disk->assertExists($facility->logo_path);
        $this->getJson('/api/staff/me')->assertOk()->assertJsonPath('hospitals.0.facility.logo_url', $response->json('logo_url'));
        $this->assertNotEmpty($response->json('logo_url'));
    }

    public function test_tenant_provisioning_occurs_after_the_central_transaction_and_can_resume(): void
    {
        Storage::fake('public');
        $user = User::create(['name' => 'Ada', 'email' => 'ada@example.test']);
        $this->withToken($this->staffToken($user));
        $image = UploadedFile::fake()->image('logo.png', 50, 50);
        $body = $this->details(['logo' => base64_encode($image->getContent())]);
        $provisions = 0;
        Event::listen(DatabaseCreated::class, function () use (&$provisions): void {
            $this->assertSame(0, DB::connection(config('tenancy.database.central_connection'))->transactionLevel(), 'Tenant DDL must run outside a central transaction');
            $provisions++;
            throw new \RuntimeException('Simulated failure after database creation');
        });
        $this->postJson('/api/staff/facilities', $body)->assertServerError();
        $facility = Facility::firstOrFail();
        $this->facilities[] = $facility;
        $this->assertDatabaseCount('facility_memberships', 1);
        $this->assertDatabaseCount('audit_log', 1);
        Storage::disk('public')->assertExists($facility->logo_path);
        Event::forget(DatabaseCreated::class);
        $this->postJson('/api/staff/facilities', $body)->assertOk()->assertJsonPath('facility_id', $facility->id);
        $this->assertSame(1, $provisions);
        $this->assertDatabaseCount('tenants', 1);
        $this->assertTrue((bool) $facility->fresh()->registration_provisioned);
    }
}
