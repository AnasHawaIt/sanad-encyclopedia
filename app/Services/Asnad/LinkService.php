<?php

namespace App\Services\Asnad;

use App\Models\Asnad;
use App\Models\Book;
use App\Models\Ijaza;
use App\Models\User;
use App\Services\Audit\ActivityLogger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ربط الكتب والأسانيد والإجازات (book_asnad، ijaza_asnad، ijaza_book): كل واجهة تستبدل قائمة الروابط
 * من جهة المالك. لا يُحذف رابط لا يراه المستخدم: من لا يرى سجلًا لا يستطيع فكّه دون قصد.
 */
class LinkService
{
    public function __construct(private ActivityLogger $log) {}

    /** @param list<array{asnad_id:int,book_edition_id?:int|null,notes?:string|null}> $items */
    public function syncBookAsnads(User $actor, Book $book, array $items): void
    {
        foreach ($items as $index => $item) {
            $this->assertEditionBelongs($item['book_edition_id'] ?? null, $book->getKey(), "items.{$index}.book_edition_id");
        }

        $this->sync($actor, $book, $book->asnads(), Asnad::class, 'asnad_id', $items, 'books.asnads_linked', fn (array $item) => [
            'book_edition_id' => $item['book_edition_id'] ?? null,
            'notes' => $item['notes'] ?? null,
        ]);
    }

    /** @param list<array{asnad_id:int,notes?:string|null}> $items */
    public function syncIjazaAsnads(User $actor, Ijaza $ijaza, array $items): void
    {
        $this->sync($actor, $ijaza, $ijaza->asnads(), Asnad::class, 'asnad_id', $items, 'ijazas.asnads_linked', fn (array $item) => [
            'notes' => $item['notes'] ?? null,
        ]);
    }

    /** @param list<array{book_id:int,book_edition_id?:int|null,notes?:string|null}> $items */
    public function syncIjazaBooks(User $actor, Ijaza $ijaza, array $items): void
    {
        foreach ($items as $index => $item) {
            $this->assertEditionBelongs($item['book_edition_id'] ?? null, (int) $item['book_id'], "items.{$index}.book_edition_id");
        }

        $this->sync($actor, $ijaza, $ijaza->books(), Book::class, 'book_id', $items, 'ijazas.books_linked', fn (array $item) => [
            'book_edition_id' => $item['book_edition_id'] ?? null,
            'notes' => $item['notes'] ?? null,
        ]);
    }

    /**
     * @param  class-string<Model>  $targetClass
     * @param  list<array<string,mixed>>  $items
     * @param  callable(array<string,mixed>):array<string,mixed>  $pivot
     */
    private function sync(User $actor, Model $owner, BelongsToMany $relation, string $targetClass, string $key, array $items, string $event, callable $pivot): void
    {
        $ids = array_map(fn ($i) => (int) $i[$key], $items);
        if (count($ids) !== count(array_unique($ids))) {
            throw ValidationException::withMessages(['items' => __('asnad.link_duplicate')]);
        }

        // الأهداف يجب أن تكون مرئية للمستخدم (وغير مؤرشفة)
        $visible = $targetClass::query()->visibleTo($actor)->whereKey($ids)->pluck('id')->map(fn ($i) => (int) $i)->all();
        if (count($visible) !== count($ids)) {
            throw ValidationException::withMessages(['items' => __('asnad.link_target_hidden')]);
        }

        DB::transaction(function () use ($actor, $owner, $relation, $targetClass, $key, $items, $ids, $event, $pivot) {
            $related = $relation->getRelated();
            $currentVisible = $targetClass::query()->visibleTo($actor)
                ->whereIn($related->getQualifiedKeyName(), $relation->newPivotQuery()->pluck($key)->all())
                ->pluck('id')->map(fn ($i) => (int) $i)->all();

            // لا نفكّ إلا ما يراه المستخدم؛ الروابط المخفية عنه تبقى كما هي
            $toDetach = array_values(array_diff($currentVisible, $ids));
            if ($toDetach !== []) {
                $relation->detach($toDetach);
            }

            $payload = [];
            foreach ($items as $item) {
                $payload[(int) $item[$key]] = $pivot($item);
            }
            // syncWithoutDetaching يضيف الجديد ويحدّث بيانات الرابط الموجود
            $relation->syncWithoutDetaching($payload);

            $this->log->record($event, $owner, ['linked' => array_values($ids), 'detached' => $toDetach], $actor);
        });
    }

    private function assertEditionBelongs(mixed $editionId, int $bookId, string $field): void
    {
        if ($editionId === null) {
            return;
        }
        $belongs = DB::table('book_editions')->where('id', $editionId)->where('book_id', $bookId)->whereNull('deleted_at')->exists();
        if (! $belongs) {
            throw ValidationException::withMessages([$field => __('asnad.edition_not_of_book')]);
        }
    }
}
