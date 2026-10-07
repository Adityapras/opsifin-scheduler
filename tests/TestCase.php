<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

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

    /**
     * RefreshDatabase wipes whatever connection is configured. A container env or
     * cached config once pointed it at the development MySQL (7 Oct 2026), so stop
     * before any trait runs unless tests are on the in-memory SQLite database.
     */
    public function createApplication()
    {
        $app = parent::createApplication();
        $connection = $app['config']->get('database.default');
        $database = $app['config']->get("database.connections.{$connection}.database");

        if ($connection !== 'sqlite' || $database !== ':memory:') {
            throw new RuntimeException("Refusing to run tests against [{$connection}:{$database}]; tests must use sqlite :memory:.");
        }

        return $app;
    }
}
