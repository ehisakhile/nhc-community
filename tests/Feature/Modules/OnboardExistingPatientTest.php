<?php

namespace Tests\Feature\Modules;

use App\Models\Patient;
use App\Modules\Patients\FacilityJoining;
use App\Services\PatientIdentity;
use Tests\Feature\Auth\AuthTestCase;

/** A patient already on NHC joins another hospital without registering again. */
class OnboardExistingPatientTest extends AuthTestCase
{
    private Patient $patient;

    protected function setUp(): void
    {
        parent::setUp();
        $this->patient = app(PatientIdentity::class)->registerAccountHolder('08062345678', [
            'first_name' => 'Ada', 'last_name' => 'Obi', 'date_of_birth' => '1990-04-01', 'gender' => 'female',
        ]);
    }

    public function test_find_shows_a_masked_preview_until_the_patient_agrees(): void
    {
        $b = $this->hospital('Neurosurgery Hospital');
        $desk = $this->staffToken($this->staff($b, 'front_desk', 'desk@example.com'));

        $this->withToken($desk)->postJson("/api/staff/facilities/{$b->id}/patients/find", ['lookup' => '0806 234 5678'])
            ->assertOk()
            ->assertJsonPath('here', false)
            ->assertJsonPath('preview.name', 'A•• O••')
            ->assertJsonPath('preview.birth_year', 1990)
            ->assertJsonMissingPath('patient');
    }

    public function test_a_code_to_their_phone_onboards_them_with_their_profile(): void
    {
        $b = $this->hospital('Neurosurgery Hospital');
        $desk = $this->staffToken($this->staff($b, 'front_desk', 'desk@example.com'));

        $challenge = $this->withToken($desk)->postJson("/api/staff/facilities/{$b->id}/patients/join", ['nhc_id' => 'NHC-8062345678'])
            ->assertOk()->json('challenge');
        $this->assertStringContainsString('Neurosurgery Hospital', $this->lastSms);

        $this->withToken($desk)->postJson("/api/staff/facilities/{$b->id}/patients/join/verify", ['challenge' => $challenge, 'code' => $this->smsCode()])
            ->assertCreated()->assertJsonPath('first_name', 'Ada')->assertJsonPath('date_of_birth', '1990-04-01');

        $this->assertSame(1, Patient::count());
        $this->withToken($desk)->getJson("/api/staff/facilities/{$b->id}/patients")->assertOk()->assertJsonCount(1)
            ->assertJsonPath('0.nhc_id', 'NHC-8062345678');
        $this->withToken($desk)->postJson("/api/staff/facilities/{$b->id}/patients/find", ['lookup' => 'NHC-8062345678'])
            ->assertJsonPath('here', true)->assertJsonPath('patient.last_name', 'Obi');
    }

    public function test_a_wrong_code_onboards_nobody(): void
    {
        $b = $this->hospital();
        $desk = $this->staffToken($this->staff($b, 'front_desk', 'desk@example.com'));
        $challenge = $this->withToken($desk)->postJson("/api/staff/facilities/{$b->id}/patients/join", ['nhc_id' => 'NHC-8062345678'])->json('challenge');

        $this->withToken($desk)->postJson("/api/staff/facilities/{$b->id}/patients/join/verify", ['challenge' => $challenge, 'code' => '000000'])
            ->assertStatus(422);
        $this->assertCount(0, app(FacilityJoining::class)->ownLinks($this->patient));
    }

    public function test_a_hospital_cannot_see_a_profile_of_someone_who_is_not_its_patient(): void
    {
        $b = $this->hospital();
        $desk = $this->staffToken($this->staff($b, 'front_desk', 'desk@example.com'));

        $this->withToken($desk)->getJson("/api/staff/facilities/{$b->id}/patients/NHC-8062345678")->assertNotFound();
        $this->withToken($desk)->getJson("/api/staff/facilities/{$b->id}/patients")->assertJsonCount(0);
    }

    public function test_cashiers_cannot_onboard(): void
    {
        $b = $this->hospital();
        $cashier = $this->staffToken($this->staff($b, 'cashier', 'cash@example.com'));

        $this->withToken($cashier)->postJson("/api/staff/facilities/{$b->id}/patients/find", ['lookup' => 'NHC-8062345678'])->assertForbidden();
    }
}
