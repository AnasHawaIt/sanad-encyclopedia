<?php

namespace Database\Factories;

use App\Models\Asnad;
use App\Models\AsnadNode;
use App\Models\Person;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AsnadNode> */
class AsnadNodeFactory extends Factory
{
    protected $model = AsnadNode::class;

    public function definition(): array
    {
        return [
            'asnad_id' => Asnad::factory(),
            'sequence_number' => 1,
            'person_id' => Person::factory(),
        ];
    }

    /** عنصر غير محقق لم يُربط بشخص بعد. */
    public function unresolved(string $name = 'راوٍ غير محقق'): static
    {
        return $this->state(['person_id' => null, 'unresolved_name' => $name]);
    }
}
