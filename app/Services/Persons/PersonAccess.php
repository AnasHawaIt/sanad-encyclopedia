<?php

namespace App\Services\Persons;

use App\Models\Person;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/** يتأكد أن الأعلام المشار إليهم من سجل آخر قائمون ومرئيون لمن يحرّر. */
class PersonAccess
{
    /** @param list<int|string> $ids */
    public function assertUsable(User $actor, array $ids, string $field): void
    {
        $ids = array_values(array_unique(array_map('intval', array_filter($ids, fn ($i) => $i !== null && $i !== ''))));
        if ($ids === []) {
            return;
        }

        $found = Person::query()->canonical()->visibleTo($actor)->whereKey($ids)->pluck('id')->all();
        if (count($found) !== count($ids)) {
            throw ValidationException::withMessages([$field => __('records.relation_target_hidden')]);
        }
    }
}
