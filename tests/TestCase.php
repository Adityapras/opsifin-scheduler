<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        $compiledViewPath = '/tmp/opsifin-crontab-test-views';

        if (! is_dir($compiledViewPath)) {
            mkdir($compiledViewPath, 0775, true);
        }

        parent::setUp();
    }
}
