<?php

namespace Tests\Feature;

use Tests\TestCase;

class TestDatabaseGuardTest extends TestCase
{
    public function test_tests_use_a_dedicated_test_database(): void
    {
        $connection = config('database.default');

        $this->assertSame('mysql', $connection);
        $this->assertStringEndsWith('_test', (string) config("database.connections.{$connection}.database"));
    }
}
