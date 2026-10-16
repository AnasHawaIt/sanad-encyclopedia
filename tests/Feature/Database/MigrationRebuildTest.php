<?php

namespace Tests\Feature\Database;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\RefusesNonTestDatabase;
use Tests\TestCase;

/**
 * يتأكد أن القاعدة تُعاد من الصفر (migrate:fresh) وأن كل down() يعمل (migrate:reset) ثم تُبنى من جديد.
 * يستخدم DatabaseMigrations لأن إنشاء الجداول وحذفها لا يعمل داخل معاملة.
 */
class MigrationRebuildTest extends TestCase
{
    use DatabaseMigrations;
    use RefusesNonTestDatabase;

    public function test_fresh_migration_builds_every_table(): void
    {
        foreach (SchemaTest::TABLES as $table) {
            $this->assertTrue(Schema::hasTable($table), "الجدول غير موجود بعد migrate:fresh: {$table}");
        }
    }

    public function test_every_migration_can_be_rolled_back_and_run_again(): void
    {
        $this->artisan('migrate:reset')->assertExitCode(0);

        foreach (SchemaTest::TABLES as $table) {
            $this->assertFalse(Schema::hasTable($table), "الجدول بقي بعد migrate:reset: {$table}");
        }

        $this->artisan('migrate')->assertExitCode(0);

        foreach (SchemaTest::TABLES as $table) {
            $this->assertTrue(Schema::hasTable($table), "الجدول غير موجود بعد إعادة البناء: {$table}");
        }
    }
}
