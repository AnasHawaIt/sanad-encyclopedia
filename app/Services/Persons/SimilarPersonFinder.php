<?php

namespace App\Services\Persons;

use App\Models\Person;
use App\Models\User;
use App\Services\Search\ArabicNormalizer;
use Illuminate\Database\Eloquent\Builder;

/**
 * يقترح أعلامًا قد يكونون الشخص نفسه. هذا اقتراح للمراجع فقط: لا يكتب شيئًا في قاعدة البيانات
 * ولا يدمج ولا يغيّر أي سجل، فتشابه الأسماء لا يعني اتحاد أصحابها.
 */
class SimilarPersonFinder
{
    private const THRESHOLD = 50;

    /** أدوات النسب والكنى الشائعة جدًا: لا تصلح وحدها لجلب المرشحين. */
    private const COMMON = ['ابن', 'بن', 'ابو', 'ابي', 'ام', 'بنت', 'عبد'];

    public function __construct(private ArabicNormalizer $normalizer) {}

    /** @return list<array{person: Person, score: int, reasons: list<string>}> */
    public function for(Person $person, ?User $viewer, int $limit = 10): array
    {
        $person->loadMissing('nameVariants');
        $mine = $this->nameSets($person);

        // نجلب المرشحين بأطول ثلاث كلمات مميِّزة؛ أي كلمة منها تكفي، ثم يحكم التقييم.
        $tokens = collect($mine)->flatten()->unique()
            ->reject(fn (string $t) => mb_strlen($t) < 3 || in_array($t, self::COMMON, true))
            ->sortByDesc(fn (string $t) => mb_strlen($t))->take(3)->values();
        if ($tokens->isEmpty()) {
            return [];
        }

        $candidates = Person::query()
            ->canonical()->visibleTo($viewer)->whereKeyNot($person->getKey())
            ->where(function (Builder $q) use ($tokens) {
                foreach ($tokens as $token) {
                    $like = '%'.$this->normalizer->escapeLike($token).'%';
                    $q->orWhere('name_search', 'like', $like)
                        ->orWhereHas('nameVariants', fn (Builder $v) => $v->where('name_search', 'like', $like));
                }
            })
            ->with('nameVariants')->orderBy('id')->limit(300)->get();

        $scored = [];
        foreach ($candidates as $candidate) {
            [$score, $reasons] = $this->score($person, $mine, $candidate);
            if ($score >= self::THRESHOLD) {
                $scored[] = ['person' => $candidate, 'score' => $score, 'reasons' => $reasons];
            }
        }

        usort($scored, fn ($a, $b) => [$b['score'], $a['person']->id] <=> [$a['score'], $b['person']->id]);

        return array_slice($scored, 0, $limit);
    }

    /** @return array{0:int,1:list<string>} */
    private function score(Person $person, array $mine, Person $other): array
    {
        $theirs = $this->nameSets($other);
        $best = 0.0;
        $identical = false;
        foreach ($mine as $a) {
            foreach ($theirs as $b) {
                $union = count(array_unique(array_merge($a, $b)));
                $shared = count(array_intersect($a, $b));
                $jaccard = $union === 0 ? 0.0 : $shared / $union;
                if ($jaccard > $best) {
                    $best = $jaccard;
                }
                if ($a !== [] && count($a) === count($b) && $shared === count($a)) {
                    $identical = true;
                }
            }
        }

        $score = (int) round($best * 100);
        $reasons = [];
        if ($identical) {
            $reasons[] = __('records.similar.same_name');
        } elseif ($best > 0) {
            $reasons[] = __('records.similar.shared_words');
        }

        if ($this->same($person->kunyah, $other->kunyah)) {
            $score += 10;
            $reasons[] = __('records.similar.same_kunyah');
        }
        if ($this->same($person->nisbah, $other->nisbah)) {
            $score += 5;
            $reasons[] = __('records.similar.same_nisbah');
        }
        if ($person->death_year_h !== null && $other->death_year_h !== null) {
            $gap = abs($person->death_year_h - $other->death_year_h);
            if ($gap <= 2) {
                $score += 15;
                $reasons[] = __('records.similar.close_death');
            } elseif ($gap > 25) {
                $score -= 30;
                $reasons[] = __('records.similar.far_death');
            }
        }

        return [max(0, min(100, $score)), $reasons];
    }

    /** @return list<list<string>> كلمات الاسم المعتمد ثم كلمات كل صيغة بديلة */
    private function nameSets(Person $person): array
    {
        $sets = [$this->normalizer->tokens($person->name_search ?: $person->primary_name)];
        foreach ($person->nameVariants as $variant) {
            $sets[] = $this->normalizer->tokens($variant->name_search ?: $variant->name);
        }

        return array_values(array_filter($sets));
    }

    private function same(?string $a, ?string $b): bool
    {
        $a = $this->normalizer->normalize($a);

        return $a !== '' && $a === $this->normalizer->normalize($b);
    }
}
