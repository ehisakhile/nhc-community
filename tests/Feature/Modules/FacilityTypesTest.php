<?php

namespace Tests\Feature\Modules;

use App\Models\FacilityMembership;
use App\Modules\FeatureHub\Features;
use App\Support\FacilityTypes;
use Tests\Feature\Auth\AuthTestCase;

/** A facility's type picks its roles, its features and what its portal looks like. */
class FacilityTypesTest extends AuthTestCase
{
    public function test_every_type_names_real_roles_and_features(): void
    {
        $features = app(Features::class)->catalogue()->pluck('key')->all();
        foreach (config('nhc.facility_types') as $key => $type) {
            $this->assertSame([], array_diff($type['roles'], array_keys(config('nhc.roles'))), "$key has an unknown role");
            $this->assertSame([], array_diff(array_keys($type['features']), $features), "$key has an unknown feature");
            $this->assertContains('admin', $type['roles'], "$key needs an admin");
        }
    }

    public function test_a_pharmacy_gets_pharmacy_things_and_not_a_hospitals(): void
    {
        $pharmacy = $this->hospital('Good Health Pharmacy', type: 'pharmacy');
        $admin = $this->staffToken($this->staff($pharmacy, 'admin', 'owner@pharm.example'));

        $hub = $this->withToken($admin)->getJson("/api/staff/facilities/{$pharmacy->id}/features")->assertOk();
        $hub->assertJsonPath('facility.type_label', 'Pharmacy')->assertJsonPath('facility.people_label', 'Customers');
        $offered = collect($hub->json('features'))->pluck('key');
        $this->assertContains('messaging', $offered);
        $this->assertNotContains('clinical_records', $offered);
        $this->assertNotContains('wards', $offered);

        // Not offered: can't be switched on, and its routes stay closed.
        $this->fresh()->withToken($admin)->putJson("/api/staff/facilities/{$pharmacy->id}/features/clinical_records", ['enabled' => true])
            ->assertUnprocessable()->assertJsonPath('message', "Clinical records isn't offered for a Pharmacy.");
        $this->fresh()->withToken($admin)->getJson("/api/staff/facilities/{$pharmacy->id}/visits")->assertForbidden();

        // Only a pharmacy's roles can be invited.
        $invite = fn (string $role) => $this->fresh()->withToken($admin)->postJson("/api/staff/facilities/{$pharmacy->id}/invites",
            ['email' => "$role@pharm.example", 'name' => 'New '.$role, 'role' => $role]);
        $invite('midwife')->assertUnprocessable();
        $invite('pharmacist')->assertCreated();

        $me = $this->fresh()->withToken($admin)->getJson('/api/staff/me')->json('hospitals.0');
        $this->assertSame('pharmacy', $me['facility']['type']);
        $this->assertEqualsCanonicalizing(['admin', 'pharmacist', 'pharmacy_technician', 'front_desk', 'cashier'], collect($me['staff_roles'])->pluck('role')->all());
        $this->assertNotContains('clinical_records', $me['features']);
    }

    public function test_a_hospital_gets_the_most_by_default(): void
    {
        $hospital = $this->hospital();
        $dental = $this->hospital('Smile Dental', type: 'dental_clinic');
        $features = app(Features::class);

        $this->assertGreaterThan(count($features->available($dental)), count($features->available($hospital)));
        foreach (['clinical_records', 'prescriptions', 'record_sharing'] as $key) {
            $this->assertTrue($features->enabled($dental, $key), "dental clinics start with $key");
        }
        $this->assertContains('dentist', FacilityTypes::roles($dental));
        $this->assertNotContains('pharmacist', FacilityTypes::roles($dental));
    }

    public function test_physiotherapists_work_solo_in_a_clinic_or_in_a_hospital_without_prescribing(): void
    {
        $this->assertSame('MRTB', config('nhc.roles.physiotherapist.licence'));
        $this->assertNotContains('prescriptions.write', config('nhc.roles.physiotherapist.permissions'));
        $this->assertContains('care.manage', config('nhc.roles.physiotherapist.permissions'));

        $clinic = $this->hospital('Stride Physio', type: 'physiotherapy_clinic');
        $solo = $this->hospital('Ada Physio', type: 'solo');
        $this->assertContains('physiotherapist', FacilityTypes::roles($clinic));
        $this->assertContains('physiotherapist', FacilityTypes::roles($solo));
        $this->assertContains('physiotherapist', FacilityTypes::roles($this->hospital()));
        $this->assertNotContains('pharmacist', FacilityTypes::roles($clinic));

        $admin = $this->staffToken($this->staff($clinic, 'admin', 'owner@stride.example'));
        $hub = $this->withToken($admin)->getJson("/api/staff/facilities/{$clinic->id}/features")->assertOk();
        $hub->assertJsonPath('facility.type_label', 'Physiotherapy clinic');
        $offered = collect($hub->json('features'))->pluck('key');
        $this->assertContains('appointments', $offered);
        $this->assertNotContains('pharmacy', $offered);
        $this->fresh()->withToken($admin)->postJson("/api/staff/facilities/{$clinic->id}/invites",
            ['email' => 'physio@stride.example', 'name' => 'Tunde Physio', 'role' => 'physiotherapist'])->assertCreated();
    }

    public function test_offered_but_off_features_can_be_switched_on(): void
    {
        $lab = $this->hospital('City Lab', type: 'laboratory');
        $admin = $this->staffToken($this->staff($lab, 'admin', 'admin@lab.example'));

        $this->assertTrue(app(Features::class)->enabled($lab, 'record_sharing'));
        $this->assertNull(FacilityTypes::offers($lab, 'clinical_records'));
        $this->withToken($admin)->putJson("/api/staff/facilities/{$lab->id}/features/clinical_records", ['enabled' => true])->assertUnprocessable();
    }

    public function test_staff_me_preserves_solo_type_and_excludes_non_active_memberships(): void
    {
        $solo = $this->hospital('Ada Solo Practice', type: 'solo');
        $user = $this->staff($solo, 'doctor', 'ada@solo.example');
        $other = $this->hospital('Old Clinic', type: 'clinic');
        FacilityMembership::create([
            'user_id' => $user->id, 'facility_id' => $other->id,
            'role' => 'doctor', 'status' => 'revoked',
        ]);
        $this->withToken($this->staffToken($user))->getJson('/api/staff/me')->assertOk()
            ->assertJsonCount(1, 'hospitals')
            ->assertJsonPath('hospitals.0.facility.type', 'solo')
            ->assertJsonPath('hospitals.0.facility.type_label', 'Solo professional')
            ->assertJsonPath('hospitals.0.facility.status', 'active')
            ->assertJsonPath('hospitals.0.role', 'doctor')
            ->assertJsonStructure(['hospitals' => [['permissions', 'features']]]);
    }

    public function test_an_unknown_or_missing_type_is_treated_as_a_hospital(): void
    {
        $old = $this->hospital('Old Record', type: 'something_else');
        $this->assertSame('hospital', FacilityTypes::key($old));
        $this->assertTrue(app(Features::class)->enabled($old, 'clinical_records'));
    }
}
