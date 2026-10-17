<?php

namespace App\Enums\Concerns;

trait HasOptions
{
    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }

    /** @return list<array{value:string,label:string}> للواجهة: لا تُكتب القوائم نصًّا في الواجهة */
    public static function options(): array
    {
        return array_map(fn (self $case) => ['value' => $case->value, 'label' => $case->label()], self::cases());
    }
}
