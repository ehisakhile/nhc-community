<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ProxyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Route::get('/_ip', fn () => request()->ip());
    }

    public function test_the_real_client_ip_comes_from_nginx_on_the_private_network(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '172.17.0.1'])
            ->withHeader('X-Forwarded-For', '102.89.1.1')
            ->get('/_ip')->assertSee('102.89.1.1');
    }

    public function test_a_client_cannot_fake_its_ip(): void
    {
        // nginx appends the real address after whatever the client sent.
        $this->withServerVariables(['REMOTE_ADDR' => '172.17.0.1'])
            ->withHeader('X-Forwarded-For', '1.2.3.4, 102.89.1.1')
            ->get('/_ip')->assertSee('102.89.1.1');

        // Straight from the internet, a forwarded header is ignored.
        $this->withServerVariables(['REMOTE_ADDR' => '102.89.1.1'])
            ->withHeader('X-Forwarded-For', '1.2.3.4')
            ->get('/_ip')->assertSee('102.89.1.1');
    }
}
