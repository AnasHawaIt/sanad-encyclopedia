<?php

namespace Database\Factories;

use App\Enums\PublicationStatus;
use App\Enums\VerificationStatus;
use App\Models\Person;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * بيانات للاختبار والتطوير فقط، وكل اسم فيها يحمل كلمة «تجريبي» ورقماً.
 *
 * @extends Factory<Person>
 */
class PersonFactory extends Factory
{
    protected $model = Person::class;

    private static int $counter = 0;

    public function definition(): array
    {
        $name = 'شخص تجريبي '.++self::$counter;

        return [
            'primary_name' => $name,
            'full_name' => $name,
            'name_search' => $name,
            'verification_status' => VerificationStatus::Draft,
            'publication_status' => PublicationStatus::Unpublished,
        ];
    }

    public function verified(): static
    {
        return $this->state(['verification_status' => VerificationStatus::Verified]);
    }

    public function published(): static
    {
        return $this->state([
            'verification_status' => VerificationStatus::Verified,
            'publication_status' => PublicationStatus::Published,
        ]);
    }
}
