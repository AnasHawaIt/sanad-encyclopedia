<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class TestDatabaseGuardTest extends TestCase
{
    public function test_suite_runs_against_a_test_database(): void
    {
        $this->assertStringEndsWith('_test', (string) getenv('DB_DATABASE'));
    }
}
