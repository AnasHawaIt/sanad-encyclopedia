<?php

namespace Database\Seeders;

use App\Models\Asnad;
use App\Models\AsnadNode;
use App\Models\Person;
use App\Services\Search\ArabicNormalizer;
use Illuminate\Database\Seeder;

/**
 * بيانات تجريبية لتجربة الواجهات محليًا فقط (php artisan db:seed --class=DemoAsnadSeeder).
 * ترفض العمل خارج البيئة المحلية كي لا تدخل بيانات وهمية قاعدة بيانات حقيقية.
 */
class DemoAsnadSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local')) {
            $this->command?->error('هذا المُبذِر للبيئة المحلية (APP_ENV=local) فقط.');

            return;
        }

        $normalizer = app(ArabicNormalizer::class);
        $person = fn (string $name, ?int $death = null) => Person::create([
            'primary_name' => $name, 'name_search' => $normalizer->normalize($name), 'death_year_h' => $death,
            'verification_status' => 'verified', 'publication_status' => 'published',
        ]);

        $origin = $person('الإمام الأصل [تجريبي]', 100);
        $nafi = $person('نافع [تجريبي]', 117);
        $malik = $person('مالك بن أنس [تجريبي]', 179);
        $shafii = $person('الشافعي [تجريبي]', 204);
        $ahmad = $person('أحمد بن حنبل [تجريبي]', 241);

        $make = function (string $title, array $chain) use ($normalizer) {
            $asnad = Asnad::create([
                'title' => $title.' [تجريبي]', 'title_search' => $normalizer->normalize($title.' [تجريبي]'),
                'verification_status' => 'verified', 'publication_status' => 'published',
            ]);
            foreach ($chain as $i => $item) {
                AsnadNode::create(['asnad_id' => $asnad->id, 'sequence_number' => $i + 1]
                    + (is_string($item) ? ['unresolved_name' => $item] : ['person_id' => $item->id]));
            }
        };

        // الأقرب إلينا أولًا
        $make('سند الشافعي', [$shafii, $malik, $nafi, $origin]);
        $make('سند أحمد', [$ahmad, $malik, $nafi, $origin]);
        $make('سند فيه حلقة غير محسومة', ['راوٍ مجهول', $malik, $origin]);

        $this->command?->info('أُنشئت 5 أعلام و3 أسانيد تجريبية.');
    }
}
