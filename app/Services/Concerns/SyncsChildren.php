<?php

namespace App\Services\Concerns;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** أدوات مشتركة للخدمات: مزامنة الصفوف التابعة بالمعرّف، وحساب فرق التعديل لسجل التدقيق. */
trait SyncsChildren
{
    /**
     * يزامن صفوفًا تابعة: عنصر بمعرّف يُحدَّث، وبلا معرّف يُنشأ، وما غاب معرّفه يُحذف.
     * معرّف لا يتبع هذا السجل يُرفض ولا يُنشأ بديل عنه.
     *
     * @param  list<array<string,mixed>>  $items
     * @param  Closure(array<string,mixed>):array<string,mixed>  $map
     * @return bool هل تغيّر شيء
     */
    protected function syncChildren(HasMany $relation, array $items, Closure $map): bool
    {
        $existing = $relation->get()->keyBy(fn (Model $m) => $m->getKey());
        $keep = [];
        $changed = false;

        foreach ($items as $index => $item) {
            $attributes = $map($item);

            if (! empty($item['id'])) {
                $model = $existing->get((int) $item['id']);
                if ($model === null) {
                    throw ValidationException::withMessages(["items.{$index}.id" => __('records.child_not_found')]);
                }
                $model->fill($attributes);
                if ($model->isDirty()) {
                    $model->save();
                    $changed = true;
                }
                $keep[] = $model->getKey();
            } else {
                $keep[] = $relation->create($attributes)->getKey();
                $changed = true;
            }
        }

        $stale = $existing->except($keep);
        $stale->each(fn (Model $m) => $m->delete());

        return $changed || $stale->isNotEmpty();
    }

    /** @return array<string,array{from:mixed,to:mixed}> الحقول التي تغيّرت في آخر حفظ، بقيم قصيرة */
    protected function diff(Model $model, array $ignore = []): array
    {
        $changes = Arr::except($model->getChanges(), array_merge(['updated_at', 'updated_by'], $ignore));
        $previous = $model->getPrevious();
        $result = [];
        foreach ($changes as $key => $value) {
            $result[$key] = ['from' => $this->short($previous[$key] ?? null), 'to' => $this->short($value)];
        }

        return $result;
    }

    private function short(mixed $value): mixed
    {
        return is_string($value) ? Str::limit($value, 200) : $value;
    }
}
