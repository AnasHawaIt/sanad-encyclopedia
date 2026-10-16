<?php

namespace Database\Factories;

use App\Models\Source;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Source> */
class SourceFactory extends Factory
{
    protected $model = Source::class;

    private static int $counter = 0;

    public function definition(): array
    {
        return [
            'title' => 'مصدر تجريبي '.++self::$counter,
            'source_type' => 'book',
        ];
    }
}
