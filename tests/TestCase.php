<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Schema;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The feature catalogue comes from the modules, as on every deploy.
        if (Schema::hasTable('features')) {
            $this->artisan('nhc:features:sync')->run();
        }
    }
}
