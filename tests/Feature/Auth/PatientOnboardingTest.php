<?php

namespace Tests\Feature\Auth;

use App\Mail\NhcWelcome;
use App\Models\Patient;
use App\Models\PatientIdentityDocument;
use App\Modules\Patients\FacilityJoining;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

class PatientOnboardingTest extends AuthTestCase
{
    private const PATIENT = [
        'phone' => '0806 234 5678', 'id_type' => 'nin', 'id_number' => '12345678901', 'email' => 'Ada@Example.com',
        'first_name' => 'Ada', 'last_name' => 'Obi', 'date_of_birth' => '1990-04-01', 'gender' => 'female',
    ];

    private function onboard(string $token, string $facilityId, array $overrides = [], ?string $code = null)
    {
        $challenge = $this->withToken($token)
            ->postJson("/api/staff/facilities/{$facilityId}/patients", $overrides + self::PATIENT)
            ->assertOk()->json('challenge');

        return $this->withToken($token)->postJson("/api/staff/facilities/{$facilityId}/patients/verify", [
            'challenge' => $challenge, 'code' => $code ?? $this->smsCode(),
        ]);
    }

    public function test_the_front_desk_registers_a_patient_with_an_id_and_a_text_code(): void
    {
        $facility = $this->hospital();
        $desk = $this->staffToken($this->staff($facility, 'front_desk', 'desk@example.com'));

        $this->onboard($desk, $facility->id)->assertCreated()
            ->assertJsonPath('nhc_id', 'NHC-8062345678')
            ->assertJsonPath('linked_to_hospify', false);

        Http::assertSent(fn ($r) => $r['to'] === '2348062345678' && str_contains($r['sms'], 'General Hospital'));
        $patient = Patient::first();
        $this->assertSame('ada@example.com', $patient->email);
        $this->assertSame('8901', $patient->identityDocuments()->first()->last_four);
        $this->assertStringNotContainsString('12345678901', PatientIdentityDocument::first()->getRawOriginal('number'));
        // An invitation only: nothing about the visit, and no account made.
        Mail::assertSent(NhcWelcome::class, fn ($m) => $m->hasTo('ada@example.com'));
    }

    public function test_a_wrong_text_code_registers_nobody(): void
    {
        $facility = $this->hospital();
        $desk = $this->staffToken($this->staff($facility, 'front_desk', 'desk@example.com'));

        $this->onboard($desk, $facility->id, code: '000000')->assertStatus(422);
        $this->assertSame(0, Patient::count());
    }

    public function test_doctors_and_cashiers_cannot_register_patients(): void
    {
        $facility = $this->hospital();

        foreach (['doctor', 'cashier'] as $role) {
            $this->fresh()->withToken($this->staffToken($this->staff($facility, $role, "$role@example.com")))
                ->postJson("/api/staff/facilities/{$facility->id}/patients", self::PATIENT)->assertForbidden();
        }
    }

    public function test_staff_cannot_register_patients_at_another_hospital(): void
    {
        $mine = $this->hospital('Mine');
        $other = $this->hospital('Other');
        $desk = $this->staffToken($this->staff($mine, 'front_desk', 'desk@example.com'));

        $this->withToken($desk)->postJson("/api/staff/facilities/{$other->id}/patients", self::PATIENT)->assertForbidden();
    }

    public function test_one_id_document_cannot_open_two_records(): void
    {
        $facility = $this->hospital();
        $desk = $this->staffToken($this->staff($facility, 'front_desk', 'desk@example.com'));
        $this->onboard($desk, $facility->id)->assertCreated();

        $this->withToken($desk)->postJson("/api/staff/facilities/{$facility->id}/patients",
            ['phone' => '08031112222', 'email' => null] + self::PATIENT)->assertStatus(422);
    }

    public function test_an_id_must_look_like_that_kind_of_id(): void
    {
        $facility = $this->hospital();
        $desk = $this->staffToken($this->staff($facility, 'front_desk', 'desk@example.com'));

        $this->withToken($desk)->postJson("/api/staff/facilities/{$facility->id}/patients",
            ['id_number' => '123'] + self::PATIENT)->assertStatus(422);
    }

    public function test_a_second_hospital_links_the_same_nhc_id_after_a_new_code(): void
    {
        $a = $this->hospital('Hospital A');
        $b = $this->hospital('Hospital B');
        $this->onboard($this->staffToken($this->staff($a, 'front_desk', 'a@example.com')), $a->id)->assertCreated();

        $this->fresh()->onboard($this->staffToken($this->staff($b, 'records_officer', 'b@example.com')), $b->id)
            ->assertOk()
            ->assertJsonPath('already_registered', true)
            ->assertJsonPath('nhc_id', 'NHC-8062345678');

        $this->assertSame(1, Patient::count());
        $this->assertCount(2, app(FacilityJoining::class)->ownLinks(Patient::first()));
    }

    public function test_a_code_sent_by_one_staff_member_cannot_be_used_by_another(): void
    {
        $facility = $this->hospital();
        $desk = $this->staffToken($this->staff($facility, 'front_desk', 'desk@example.com'));
        $other = $this->staffToken($this->staff($facility, 'nurse', 'nurse@example.com'));

        $challenge = $this->withToken($desk)->postJson("/api/staff/facilities/{$facility->id}/patients", self::PATIENT)->json('challenge');

        $this->fresh()->withToken($other)->postJson("/api/staff/facilities/{$facility->id}/patients/verify", [
            'challenge' => $challenge, 'code' => $this->smsCode(),
        ])->assertStatus(422);
        $this->assertSame(0, Patient::count());
    }

    public function test_hospitals_not_yet_live_cannot_register_patients(): void
    {
        $facility = $this->hospital(status: 'pending');
        $desk = $this->staffToken($this->staff($facility, 'front_desk', 'desk@example.com'));

        $this->withToken($desk)->postJson("/api/staff/facilities/{$facility->id}/patients", self::PATIENT)->assertStatus(422);
    }
}
