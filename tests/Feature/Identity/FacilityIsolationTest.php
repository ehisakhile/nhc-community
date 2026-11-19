<?php

namespace Tests\Feature\Identity;

use App\Exceptions\ImmutableRecord;
use App\Models\AuditLog;
use App\Models\Facility;
use App\Models\Patient;
use App\Models\PatientFacilityLink;
use App\Services\PatientIdentity;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class FacilityIsolationTest extends TestCase
{
    use DatabaseMigrations;

    /** @var list<Facility> */
    private array $facilities = [];

    protected function tearDown(): void
    {
        tenancy()->end();
        PatientFacilityLink::withoutGlobalScopes()->delete();

        foreach ($this->facilities as $facility) {
            $facility->delete();
        }

        parent::tearDown();
    }

    private function facility(string $name): Facility
    {
        return $this->facilities[] = Facility::create(['name' => $name, 'type' => 'hospital']);
    }

    private function link(Patient $patient, Facility $facility): void
    {
        $link = new PatientFacilityLink([
            'patient_id' => $patient->id,
            'facility_id' => $facility->id,
            'joined_via' => 'otp',
            'confirmed_at' => now(),
        ]);
        $link->save();
    }

    public function test_a_hospital_gets_its_own_back_office_database(): void
    {
        $facility = $this->facility('General Hospital');

        $this->assertFalse(Schema::hasTable('facility_settings'));

        tenancy()->initialize($facility);
        $this->assertTrue(Schema::hasTable('facility_settings'));
    }

    public function test_central_records_stay_reachable_inside_a_hospital(): void
    {
        $patient = app(PatientIdentity::class)->registerAccountHolder('08062345678', ['first_name' => 'Ada', 'last_name' => 'Obi']);

        tenancy()->initialize($this->facility('General Hospital'));

        $this->assertTrue(Patient::first()->is($patient));
    }

    public function test_one_patient_at_two_hospitals_is_one_record(): void
    {
        $patient = app(PatientIdentity::class)->registerAccountHolder('08062345678', ['first_name' => 'Ada', 'last_name' => 'Obi']);
        $a = $this->facility('Hospital A');
        $b = $this->facility('Hospital B');

        tenancy()->initialize($a);
        $this->link($patient, $a);
        tenancy()->initialize($b);
        $this->link($patient, $b);

        $this->assertSame(1, Patient::count());
        $this->assertSame(1, PatientFacilityLink::count());
    }

    public function test_a_hospital_cannot_see_another_hospitals_rows(): void
    {
        $patient = app(PatientIdentity::class)->registerAccountHolder('08062345678', ['first_name' => 'Ada', 'last_name' => 'Obi']);
        $a = $this->facility('Hospital A');
        $b = $this->facility('Hospital B');
        $this->link($patient, $a);

        tenancy()->initialize($b);

        $this->assertSame(0, PatientFacilityLink::count());
    }

    public function test_with_no_hospital_set_scoped_tables_return_nothing(): void
    {
        $patient = app(PatientIdentity::class)->registerAccountHolder('08062345678', ['first_name' => 'Ada', 'last_name' => 'Obi']);
        $this->link($patient, $this->facility('Hospital A'));

        $this->assertSame(0, PatientFacilityLink::count());
        $this->assertSame(1, PatientFacilityLink::withoutGlobalScopes()->count());
    }

    public function test_a_hospital_with_patients_cannot_be_deleted(): void
    {
        $patient = app(PatientIdentity::class)->registerAccountHolder('08062345678', ['first_name' => 'Ada', 'last_name' => 'Obi']);
        $facility = $this->facility('Hospital A');
        $this->link($patient, $facility);

        $this->expectException(QueryException::class);
        $facility->delete();
    }

    public function test_the_audit_log_can_only_be_added_to(): void
    {
        $entry = AuditLog::create(['actor_type' => 'staff', 'action' => 'patient.viewed', 'occurred_at' => now()]);

        $this->expectException(ImmutableRecord::class);
        $entry->update(['action' => 'nothing.happened']);
    }

    public function test_an_audit_entry_cannot_be_deleted(): void
    {
        $entry = AuditLog::create(['actor_type' => 'staff', 'action' => 'patient.viewed', 'occurred_at' => now()]);

        $this->expectException(ImmutableRecord::class);
        $entry->delete();
    }
}
