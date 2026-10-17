<?php

namespace Database\Factories;

use App\Models\Source;
use App\Services\Search\ArabicNormalizer;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Source> */
class SourceFactory extends Factory
{
    protected $model = Source::class;

    private static int $counter = 0;

    public function definition(): array
    {
        $title = 'مصدر تجريبي '.++self::$counter;

        return [
            'title' => $title,
            'title_search' => app(ArabicNormalizer::class)->normalize($title),
            'source_type' => 'book',
        ];
    }
}
