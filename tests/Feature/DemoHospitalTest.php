<?php

namespace Tests\Feature;

use App\Models\Facility;
use App\Models\FacilityMembership;
use App\Models\PatientFacilityLink;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Tests\TestCase;

class DemoHospitalTest extends TestCase
{
    use DatabaseMigrations;

    protected function tearDown(): void
    {
        tenancy()->end();
        PatientFacilityLink::withoutGlobalScopes()->delete();
        Facility::all()->each->delete();
        parent::tearDown();
    }

    public function test_it_creates_an_active_hospital_with_every_role_on_plus_aliases(): void
    {
        $this->artisan('nhc:demo', ['--email' => 'Ada@Example.com'])->expectsQuestion('Password for every demo account (10+ characters; press Enter to use email codes instead)', '')->assertSuccessful();
        $this->artisan('nhc:demo', ['--email' => 'ada@example.com'])->expectsQuestion('Password for every demo account (10+ characters; press Enter to use email codes instead)', '')->assertSuccessful();  // re-run is safe

        $facility = Facility::sole();
        $this->assertSame('active', $facility->status);
        $this->assertSame(count(config('nhc.facility_types.hospital.roles')), FacilityMembership::where('status', 'active')->count());
        $this->assertSame('admin', FacilityMembership::where('user_id', User::where('email', 'ada@example.com')->value('id'))->value('role'));
        $this->assertNotNull(User::where('email', 'ada+frontdesk@example.com')->first());
        $this->assertNotNull(FacilityMembership::where('role', 'doctor')->value('licence_checked_at'));
    }

    public function test_it_needs_an_email(): void
    {
        $this->artisan('nhc:demo')->assertFailed();
    }
}
