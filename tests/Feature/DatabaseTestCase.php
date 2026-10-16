<?php

namespace Tests;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\RefusesNonTestDatabase;

/**
 * أساس اختبارات قاعدة البيانات: يعيد بناء القاعدة ويلفّ كل اختبار بمعاملة،
 * ويرفض العمل على أي قاعدة لا ينتهي اسمها بـ _test.
 */
abstract class DatabaseTestCase extends TestCase
{
    use RefreshDatabase;
    use RefusesNonTestDatabase;

    /** يتأكد أن القاعدة رفضت العملية (مفتاح خارجي، فرادة، CHECK…). */
    protected function assertRejectedByDatabase(callable $callback, string $message = ''): void
    {
        try {
            $callback();
        } catch (QueryException) {
            $this->addToAssertionCount(1);

            return;
        }

        $this->fail($message !== '' ? $message : 'قبلت القاعدة سجلاً كان يجب أن ترفضه.');
    }
}
