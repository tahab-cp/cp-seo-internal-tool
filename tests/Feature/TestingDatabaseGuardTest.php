<?php

namespace Tests\Feature;

use RuntimeException;
use Tests\TestCase;

class TestingDatabaseGuardTest extends TestCase
{
    public function test_the_suite_is_configured_against_a_testing_database(): void
    {
        $connection = config('database.default');

        $this->assertStringEndsWith('_testing', config("database.connections.{$connection}.database"));
    }

    public function test_the_guard_refuses_a_non_testing_database_name(): void
    {
        $connection = config('database.default');

        config(["database.connections.{$connection}.database" => 'cp_seo_internal_tool']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Refusing to run tests against database "cp_seo_internal_tool"');

        $this->ensureTestingDatabase();
    }

    public function test_the_guard_accepts_testing_database_names(): void
    {
        $this->assertTrue($this->isTestingDatabaseName('cp_seo_internal_tool_testing'));
        $this->assertTrue($this->isTestingDatabaseName(':memory:'));
        $this->assertFalse($this->isTestingDatabaseName('cp_seo_internal_tool'));
        $this->assertFalse($this->isTestingDatabaseName('testing_cp_seo'));
    }
}
