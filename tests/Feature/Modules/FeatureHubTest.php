<?php

namespace Tests\Feature\Modules;

use App\Modules\FeatureHub\Features;
use Tests\Feature\Auth\AuthTestCase;

class FeatureHubTest extends AuthTestCase
{
    public function test_the_catalogue_comes_from_the_modules(): void
    {
        $facility = $this->hospital();
        $admin = $this->staff($facility, 'admin', 'admin@example.com');

        $features = collect($this->withToken($this->staffToken($admin))->getJson("/api/staff/facilities/{$facility->id}/features")
            ->assertOk()->assertJsonPath('can_manage', true)->json('features'))->keyBy('key');

        $this->assertTrue($features['patient_registration']['core']);
        $this->assertTrue($features['record_sharing']['enabled']);          // on by default
        $this->assertSame('coming_soon', $features['wards']['status']);
        $this->assertFalse($features['wards']['enabled']);
    }

    public function test_an_admin_switches_a_feature_off_and_its_routes_close(): void
    {
        $facility = $this->hospital();
        $admin = $this->staffToken($this->staff($facility, 'admin', 'admin@example.com'));

        $this->withToken($admin)->getJson("/api/staff/facilities/{$facility->id}/record-requests")->assertOk();
        $this->withToken($admin)->putJson("/api/staff/facilities/{$facility->id}/features/record_sharing", ['enabled' => false])
            ->assertOk()->assertJsonPath('enabled', false);
        app()->forgetInstance(Features::class);

        $this->withToken($admin)->getJson("/api/staff/facilities/{$facility->id}/record-requests")
            ->assertForbidden()->assertJsonPath('message', 'This feature is switched off at this hospital.');
        $this->assertNotContains('record_sharing', $this->withToken($admin)->getJson('/api/staff/me')->json('hospitals.0.features'));
    }

    public function test_core_and_coming_soon_features_cannot_be_switched(): void
    {
        $facility = $this->hospital();
        $admin = $this->staffToken($this->staff($facility, 'admin', 'admin@example.com'));

        $this->withToken($admin)->putJson("/api/staff/facilities/{$facility->id}/features/patient_registration", ['enabled' => false])->assertStatus(422);
        $this->withToken($admin)->putJson("/api/staff/facilities/{$facility->id}/features/wards", ['enabled' => true])->assertStatus(422);
    }

    public function test_only_admins_switch_features(): void
    {
        $facility = $this->hospital();
        $doctor = $this->staffToken($this->staff($facility, 'doctor', 'doc@example.com'));

        $this->withToken($doctor)->getJson("/api/staff/facilities/{$facility->id}/features")->assertOk()->assertJsonPath('can_manage', false);
        $this->withToken($doctor)->putJson("/api/staff/facilities/{$facility->id}/features/record_sharing", ['enabled' => false])->assertForbidden();
    }

    public function test_staff_of_another_hospital_see_nothing(): void
    {
        $mine = $this->hospital('Mine');
        $other = $this->hospital('Other');
        $admin = $this->staffToken($this->staff($mine, 'admin', 'admin@example.com'));

        $this->withToken($admin)->getJson("/api/staff/facilities/{$other->id}/features")->assertForbidden();
        $this->withToken($admin)->putJson("/api/staff/facilities/{$other->id}/features/record_sharing", ['enabled' => false])->assertForbidden();
    }
}
