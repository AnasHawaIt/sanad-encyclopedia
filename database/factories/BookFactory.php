<?php

namespace Database\Factories;

use App\Models\Book;
use App\Models\Person;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Book> */
class BookFactory extends Factory
{
    protected $model = Book::class;

    private static int $counter = 0;

    public function definition(): array
    {
        $title = 'كتاب تجريبي '.++self::$counter;

        return [
            'title' => $title,
            'title_search' => $title,
            'author_person_id' => Person::factory(),
        ];
    }

    public function withoutAuthor(): static
    {
        return $this->state(['author_person_id' => null]);
    }
}
