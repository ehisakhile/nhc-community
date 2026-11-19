<?php

namespace Tests\Feature\Identity;

use App\Exceptions\ImmutableRecord;
use App\Exceptions\PhoneOnFile;
use App\Services\PatientIdentity;
use App\Support\NigerianPhone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class NhcIdTest extends TestCase
{
    use RefreshDatabase;

    private const DETAILS = ['first_name' => 'Ada', 'last_name' => 'Obi'];

    private function identity(): PatientIdentity
    {
        return app(PatientIdentity::class);
    }

    #[DataProvider('numbers')]
    public function test_every_way_of_writing_a_number_gives_one_id(string $written): void
    {
        $this->assertSame('NHC-8062345678', $this->identity()->registerAccountHolder($written, self::DETAILS)->nhc_id);
    }

    public static function numbers(): array
    {
        return [['08062345678'], ['0806 234 5678'], ['+234 806 234 5678'], ['2348062345678'], ['8062345678']];
    }

    public function test_it_rejects_a_number_that_is_not_nigerian_mobile(): void
    {
        $this->expectException(InvalidArgumentException::class);
        NigerianPhone::normalise('01234');
    }

    public function test_changing_phone_keeps_the_id(): void
    {
        $patient = $this->identity()->registerAccountHolder('08062345678', self::DETAILS);

        $this->identity()->changePhone($patient, '09011112222');

        $patient->refresh();
        $this->assertSame('NHC-8062345678', $patient->nhc_id);
        $this->assertSame('9011112222', $patient->phone);
        $this->assertCount(2, $patient->phones);
        $this->assertNotNull($patient->phones()->where('phone', '8062345678')->first()->replaced_at);
    }

    public function test_a_recycled_number_never_gets_the_first_owners_id(): void
    {
        $first = $this->identity()->registerAccountHolder('08062345678', self::DETAILS);
        $this->identity()->changePhone($first, '09011112222');

        $second = $this->identity()->registerAccountHolder('08062345678', ['first_name' => 'Tunde', 'last_name' => 'Bello']);

        $this->assertSame('NHC-8062345678-2', $second->nhc_id);
        $this->assertSame('NHC-8062345678', $first->refresh()->nhc_id);
    }

    public function test_lookups_after_a_number_is_recycled_reach_the_right_person(): void
    {
        $first = $this->identity()->registerAccountHolder('08062345678', self::DETAILS);
        $this->identity()->changePhone($first, '09011112222');
        $second = $this->identity()->registerAccountHolder('08062345678', ['first_name' => 'Tunde', 'last_name' => 'Bello']);

        $this->assertTrue($this->identity()->find('NHC-8062345678')->is($first));
        $this->assertTrue($this->identity()->find('08062345678')->is($second));
    }

    public function test_otp_goes_to_the_phone_on_file_not_the_digits_in_the_id(): void
    {
        $patient = $this->identity()->registerAccountHolder('08062345678', self::DETAILS);
        $this->identity()->changePhone($patient, '09011112222');

        $this->assertSame('9011112222', $this->identity()->find('NHC-8062345678')->otpPhone());
    }

    public function test_a_number_on_file_goes_to_review_and_nothing_merges(): void
    {
        $this->identity()->registerAccountHolder('08062345678', self::DETAILS);

        $this->expectException(PhoneOnFile::class);
        $this->identity()->registerAccountHolder('08062345678', ['first_name' => 'Tunde', 'last_name' => 'Bello']);
    }

    public function test_a_number_cannot_be_moved_onto_someone_elses_phone(): void
    {
        $this->identity()->registerAccountHolder('08062345678', self::DETAILS);
        $other = $this->identity()->registerAccountHolder('09011112222', self::DETAILS);

        $this->expectException(PhoneOnFile::class);
        $this->identity()->changePhone($other, '08062345678');
    }

    public function test_dependants_get_their_own_id_and_otps_go_to_the_guardian(): void
    {
        $guardian = $this->identity()->registerAccountHolder('08062345678', self::DETAILS);

        $child = $this->identity()->registerDependant($guardian, ['first_name' => 'Chi', 'last_name' => 'Obi']);
        $sibling = $this->identity()->registerDependant($guardian, ['first_name' => 'Uche', 'last_name' => 'Obi']);

        $this->assertSame('NHC-8062345678-2', $child->nhc_id);
        $this->assertSame('NHC-8062345678-3', $sibling->nhc_id);
        $this->assertNull($child->phone);
        $this->assertSame('8062345678', $child->otpPhone());
    }

    public function test_a_dependant_keeps_their_id_when_they_get_their_own_phone(): void
    {
        $guardian = $this->identity()->registerAccountHolder('08062345678', self::DETAILS);
        $child = $this->identity()->registerDependant($guardian, ['first_name' => 'Chi', 'last_name' => 'Obi']);

        $this->identity()->changePhone($child, '09011112222');

        $this->assertSame('NHC-8062345678-2', $child->refresh()->nhc_id);
        $this->assertSame('9011112222', $child->otpPhone());
    }

    public function test_an_id_can_never_be_edited(): void
    {
        $patient = $this->identity()->registerAccountHolder('08062345678', self::DETAILS);

        $this->expectException(ImmutableRecord::class);
        $patient->nhc_id = 'NHC-9011112222';
        $patient->save();
    }

    public function test_a_patient_row_can_never_be_removed(): void
    {
        $patient = $this->identity()->registerAccountHolder('08062345678', self::DETAILS);

        $this->expectException(ImmutableRecord::class);
        $patient->forceDelete();
    }

    public function test_a_soft_deleted_patient_still_holds_their_id(): void
    {
        $first = $this->identity()->registerAccountHolder('08062345678', self::DETAILS);
        $this->identity()->changePhone($first, '09011112222');
        $first->delete();

        $next = $this->identity()->registerAccountHolder('08062345678', self::DETAILS);

        $this->assertSame('NHC-8062345678-2', $next->nhc_id);
    }
}
