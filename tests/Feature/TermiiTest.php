<?php

namespace Tests\Feature;

use App\Exceptions\SmsFailed;
use App\Services\Termii;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class TermiiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['services.termii' => ['api_key' => 'k', 'sender_id' => 'Hospify', 'base_url' => 'https://termii.test', 'channel' => 'generic']]);
        Exceptions::fake();
    }

    public function test_a_refused_sms_is_reported_with_termiis_reason_but_not_the_code(): void
    {
        Http::fake(['termii.test/*' => Http::response(['message' => 'Insufficient balance'], 400)]);

        try {
            app(Termii::class)->send('8062345678', 'Your code is 123456');
            $this->fail('Expected SmsFailed');
        } catch (SmsFailed $e) {
            $this->assertSame(503, $e->getStatusCode());
        }

        Exceptions::assertReported(fn (RuntimeException $e) => str_contains($e->getMessage(), 'Insufficient balance')
            && str_contains($e->getMessage(), '400') && ! str_contains($e->getMessage(), '123456'));
    }

    public function test_missing_settings_are_reported(): void
    {
        config(['services.termii.api_key' => '']);

        $this->expectException(SmsFailed::class);
        try {
            app(Termii::class)->send('8062345678', 'x');
        } finally {
            Exceptions::assertReported(fn (RuntimeException $e) => str_contains($e->getMessage(), 'TERMII_API_KEY'));
        }
    }
}
