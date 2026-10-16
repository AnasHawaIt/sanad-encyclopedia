<?php

namespace Database\Factories;

use App\Models\Asnad;
use App\Models\AsnadNode;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\Sequence;

/** @extends Factory<Asnad> */
class AsnadFactory extends Factory
{
    protected $model = Asnad::class;

    private static int $counter = 0;

    public function definition(): array
    {
        return ['title' => 'سند تجريبي '.++self::$counter];
    }

    /** ينشئ $count عنصراً بأشخاص جدد وبترتيب 1..$count. */
    public function withNodes(int $count): static
    {
        return $this->afterCreating(function (Asnad $asnad) use ($count): void {
            AsnadNode::factory()
                ->count($count)
                ->sequence(fn (Sequence $sequence) => ['sequence_number' => $sequence->index + 1])
                ->create(['asnad_id' => $asnad->id]);
        });
    }
}
