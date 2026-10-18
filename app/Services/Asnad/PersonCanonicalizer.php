<?php

namespace App\Services\Asnad;

use App\Models\Person;

/**
 * يوحّد معرّف العلَم المدموج مع الأساسي الذي دُمج فيه، حتى لا يُحسب شخص واحد شخصين
 * لمجرد أن عنصرًا ما ما زال يشير إلى السجل القديم. للقراءة فقط؛ لا يغيّر أي سجل.
 */
class PersonCanonicalizer
{
    private const MAX_DEPTH = 10;

    /**
     * @param  iterable<int|string>  $ids
     * @return array<int,int> معرّف أصلي => معرّف أساسي (الأعلام غير المدموجة تُعاد كما هي)
     */
    public function map(iterable $ids): array
    {
        $map = [];
        foreach ($ids as $id) {
            $map[(int) $id] = (int) $id;
        }

        $pending = array_keys($map);
        for ($depth = 0; $depth < self::MAX_DEPTH && $pending !== []; $depth++) {
            $merged = Person::withTrashed()
                ->whereIn('id', $pending)->whereNotNull('merged_into_person_id')
                ->pluck('merged_into_person_id', 'id');
            if ($merged->isEmpty()) {
                break;
            }
            $next = [];
            foreach ($map as $original => $current) {
                if ($merged->has($current)) {
                    $map[$original] = (int) $merged[$current];
                    $next[] = $map[$original];
                }
            }
            $pending = array_values(array_unique($next));
        }

        return $map;
    }
}
