<?php

namespace Database\Seeders;

use App\Models\Asnad;
use App\Models\AsnadNode;
use App\Models\Book;
use App\Models\Ijaza;
use App\Models\Person;
use App\Models\SanadType;
use App\Models\Source;
use Illuminate\Database\Seeder;

/**
 * بيانات تجريبية صريحة (كل اسم فيها «تجريبي») لبيئة التطوير فقط.
 * ترفض العمل خارج local وtesting، ولا تُستخدم في الإنتاج.
 */
class DevelopmentSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->command?->error('DevelopmentSeeder يعمل في بيئتي local وtesting فقط.');

            return;
        }

        $this->call(LookupSeeder::class);

        $persons = Person::factory()->count(12)->create();
        $source = Source::factory()->create();
        $sanadType = SanadType::query()->orderBy('sort_order')->first();

        $books = collect(range(0, 2))->map(
            fn (int $i) => Book::factory()->create(['author_person_id' => $persons[$i]->id])
        );

        foreach ([4, 5, 3] as $i => $length) {
            $asnad = Asnad::factory()->create(['sanad_type_id' => $sanadType?->id]);

            foreach (range(1, $length) as $sequence) {
                AsnadNode::factory()->create([
                    'asnad_id' => $asnad->id,
                    'sequence_number' => $sequence,
                    'person_id' => $persons[($i + $sequence - 1) % 12]->id,
                ]);
            }

            $asnad->books()->attach($books[$i]->id);
        }

        $ijazas = Ijaza::factory()->count(2)->create();
        $ijazas[0]->books()->attach([$books[0]->id, $books[1]->id]);
        $ijazas[0]->asnads()->attach(Asnad::query()->pluck('id')->take(2)->all());

        $persons[0]->sourceReferences()->create([
            'source_id' => $source->id,
            'page' => '1',
            'quote_text' => 'اقتباس تجريبي',
        ]);
    }
}
