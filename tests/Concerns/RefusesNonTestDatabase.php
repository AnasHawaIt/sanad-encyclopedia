<?php

namespace Tests\Concerns;

use RuntimeException;

/**
 * حماية ثانية (إضافة إلى tests/bootstrap.php): يُستدعى قبل أن يلمس RefreshDatabase
 * أو DatabaseMigrations أي جدول، فيرفض العمل على قاعدة لا ينتهي اسمها بـ _test.
 */
trait RefusesNonTestDatabase
{
    protected function setUpTraits()
    {
        $connection = config('database.default');
        $database = (string) config("database.connections.{$connection}.database");

        if (! str_ends_with($database, '_test')) {
            throw new RuntimeException(
                "Refusing to run database tests on [{$database}]: the database name must end with _test."
            );
        }

        return parent::setUpTraits();
    }
}
