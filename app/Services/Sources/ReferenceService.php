<?php

namespace App\Services\Sources;

use App\Enums\ReferenceStatus;
use App\Models\SourceReference;
use App\Models\User;
use App\Services\Audit\ActivityLogger;
use App\Services\Concerns\SyncsChildren;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * مراجع الأعلام والكتب. الباحث يُدخل المرجع «غير محقق» دائمًا، وتحقيقه أو رفضه عملية مستقلة
 * من صلاحية المراجع العلمي (reviews.decide). وأي تعديل في مضمون مرجع محقق يعيده «غير محقق»
 * لأن ما تحقق منه المراجع لم يعد هو نفسه.
 */
class ReferenceService
{
    use SyncsChildren;

    private const CONTENT = ['source_id', 'volume', 'page', 'manuscript_ref', 'quote_text', 'note'];

    public function __construct(private ActivityLogger $log) {}

    /** @return Collection<int,SourceReference> غير المراجعين لا يرون إلا المراجع المحققة */
    public function listFor(Model $entity, ?User $viewer): Collection
    {
        $query = $entity->sourceReferences()->with('source')->orderBy('id');
        if ($viewer === null || ! $viewer->can('records.view_unpublished')) {
            $query->where('reference_status', ReferenceStatus::Verified->value);
        }

        return $query->get();
    }

    public function add(User $actor, Model $entity, array $data): SourceReference
    {
        return DB::transaction(function () use ($actor, $entity, $data) {
            $reference = $entity->sourceReferences()->create(
                array_intersect_key($data, array_flip(self::CONTENT))
                + ['reference_status' => ReferenceStatus::Unverified->value, 'created_by' => $actor->getKey()]
            );
            $this->log->record('references.created', $entity, [
                'reference_id' => $reference->getKey(), 'source_id' => $reference->source_id,
            ], $actor);

            return $reference->refresh()->load('source');
        });
    }

    public function update(User $actor, Model $entity, SourceReference $reference, array $data): SourceReference
    {
        return DB::transaction(function () use ($actor, $entity, $reference, $data) {
            $reference->fill(array_intersect_key($data, array_flip(self::CONTENT)));
            if ($reference->isDirty(self::CONTENT) && $reference->reference_status !== ReferenceStatus::Unverified) {
                $reference->reference_status = ReferenceStatus::Unverified;
            }
            $reference->save();

            $changes = $this->diff($reference);
            if ($changes !== []) {
                $this->log->record('references.updated', $entity, ['reference_id' => $reference->getKey()] + $changes, $actor);
            }

            return $reference->refresh()->load('source');
        });
    }

    /** تحقيق المرجع أو رفضه أو إعادته غير محقق: قرار المراجع وحده، ويُسجَّل في التدقيق. */
    public function decide(User $actor, Model $entity, SourceReference $reference, ReferenceStatus $status): SourceReference
    {
        return DB::transaction(function () use ($actor, $entity, $reference, $status) {
            $from = $reference->reference_status;
            if ($from !== $status) {
                $reference->reference_status = $status;
                $reference->save();
                $this->log->record('references.status_changed', $entity, [
                    'reference_id' => $reference->getKey(),
                    'reference_status' => ['from' => $from->value, 'to' => $status->value],
                ], $actor);
            }

            return $reference->refresh()->load('source');
        });
    }

    public function remove(User $actor, Model $entity, SourceReference $reference): void
    {
        DB::transaction(function () use ($actor, $entity, $reference) {
            $reference->delete();
            $this->log->record('references.deleted', $entity, [
                'reference_id' => $reference->getKey(), 'source_id' => $reference->source_id,
            ], $actor);
        });
    }
}
