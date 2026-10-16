<?php

namespace Database\Factories;

use App\Models\Ijaza;
use App\Models\Person;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Ijaza> */
class IjazaFactory extends Factory
{
    protected $model = Ijaza::class;

    private static int $counter = 0;

    public function definition(): array
    {
        $n = ++self::$counter;
        $text = "نص إجازة تجريبي رقم {$n}";

        return [
            'ijaza_number' => "TEST-{$n}",
            'grantor_person_id' => Person::factory(),
            'recipient_person_id' => Person::factory(),
            'original_text' => $text,
            'text_search' => $text,
        ];
    }
}
