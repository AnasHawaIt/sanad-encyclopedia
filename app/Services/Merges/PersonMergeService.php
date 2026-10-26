<?php

namespace App\Services\Merges;

use App\Enums\MergeRequestStatus;
use App\Enums\NameVariantType;
use App\Models\Person;
use App\Models\PersonMergeRepoint;
use App\Models\PersonMergeRequest;
use App\Models\User;
use App\Services\Audit\ActivityLogger;
use App\Services\Search\ArabicNormalizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * دمج الأعلام. لا يحدث شيء تلقائيًا: طلب (باحث) ← معاينة ← اعتماد صريح من مراجع آخر ← تنفيذ داخل معاملة واحدة.
 *
 * التنفيذ لا يحذف أي سجل: العلَم المدموج يبقى بكل بياناته ويُعلَّم merged_into_person_id فيختفي من القوائم،
 * وكل صف أُعيد توجيهه إلى العلَم الأساسي يُسجَّل في person_merge_repoints بقيمته القديمة، فيكون التراجع دقيقًا:
 * لا يُعاد إلا صف ما زال يشير إلى الأساسي (أي لم يغيّره أحد بعد الدمج)، وما تغيّر يُترك ويُبلَّغ عنه.
 * بيانات العلَم الأساسي نفسها (اسمه، سنواته، سيرته) لا تُكتب فوقها أبدًا.
 */
class PersonMergeService
{
    /** @var list<array{0:string,1:string}> جداول فيها عمود يشير إلى علَم (يشمل المحذوف حذفًا ناعمًا) */
    private const TARGETS = [
        ['books', 'author_person_id'],
        ['book_contributors', 'person_id'],
        ['book_editions', 'editor_person_id'],
        ['ijazas', 'grantor_person_id'],
        ['ijazas', 'recipient_person_id'],
        ['asnad_nodes', 'person_id'],
        ['person_relations', 'teacher_person_id'],
        ['person_relations', 'student_person_id'],
        ['person_name_variants', 'person_id'],
        ['person_date_claims', 'person_id'],
        ['persons', 'merged_into_person_id'],
    ];

    /** @var array<string,array{0:string,1:string}> جدول متعدد الأشكال => [عمود المعرّف، عمود النوع] */
    private const MORPH_TARGETS = [
        'source_references' => ['referenceable_id', 'referenceable_type'],
        'attachables' => ['attachable_id', 'attachable_type'],
    ];

    private const CHUNK = 500;

    public function __construct(private ActivityLogger $log, private ArabicNormalizer $normalizer) {}

    // ------------------------------------------------------------------ الطلب

    public function request(User $actor, int $primaryId, int $secondaryId, string $reason): PersonMergeRequest
    {
        if ($primaryId === $secondaryId) {
            throw ValidationException::withMessages(['secondary_person_id' => __('merges.same_person')]);
        }

        return DB::transaction(function () use ($actor, $primaryId, $secondaryId, $reason) {
            $usable = Person::query()->canonical()->visibleTo($actor)->whereIn('id', [$primaryId, $secondaryId])->lockForUpdate()->pluck('id')->all();
            if (count($usable) !== 2) {
                throw ValidationException::withMessages(['primary_person_id' => __('merges.person_unusable')]);
            }

            $pending = PersonMergeRequest::query()->where('status', MergeRequestStatus::Pending->value)
                ->where(fn ($q) => $q->where(fn ($w) => $w->where('primary_person_id', $primaryId)->where('secondary_person_id', $secondaryId))
                    ->orWhere(fn ($w) => $w->where('primary_person_id', $secondaryId)->where('secondary_person_id', $primaryId)))
                ->exists();
            if ($pending) {
                throw ValidationException::withMessages(['primary_person_id' => __('merges.pair_pending')]);
            }

            $request = PersonMergeRequest::create([
                'primary_person_id' => $primaryId, 'secondary_person_id' => $secondaryId,
                'reason' => trim($reason), 'requested_by' => $actor->getKey(),
            ]);
            $this->log->record('merges.requested', $request, ['primary_person_id' => $primaryId, 'secondary_person_id' => $secondaryId], $actor);

            return $request->refresh();
        });
    }

    public function withdraw(User $actor, PersonMergeRequest $request): PersonMergeRequest
    {
        return DB::transaction(function () use ($actor, $request) {
            $locked = $this->lockPending($request);
            $locked->forceFill([
                'status' => MergeRequestStatus::Rejected, 'decided_by' => $actor->getKey(), 'decided_at' => now(),
                'decision_notes' => __('merges.withdrawn'),
            ])->save();
            $this->log->record('merges.withdrawn', $locked, [], $actor);

            return $locked->refresh();
        });
    }

    public function reject(User $actor, PersonMergeRequest $request, string $notes): PersonMergeRequest
    {
        if (trim($notes) === '') {
            throw ValidationException::withMessages(['notes' => __('merges.notes_required')]);
        }

        return DB::transaction(function () use ($actor, $request, $notes) {
            $locked = $this->lockPending($request);
            $this->assertNotRequester($actor, $locked);
            $locked->forceFill([
                'status' => MergeRequestStatus::Rejected, 'decided_by' => $actor->getKey(), 'decided_at' => now(), 'decision_notes' => trim($notes),
            ])->save();
            $this->log->record('merges.rejected', $locked, ['notes' => trim($notes)], $actor);

            return $locked->refresh();
        });
    }

    // ------------------------------------------------------------------ المعاينة

    /**
     * ما سيحدث لو اعتُمد الطلب الآن، دون كتابة أي شيء.
     *
     * @return array<string,mixed>
     */
    public function preview(PersonMergeRequest $request): array
    {
        $primary = Person::withTrashed()->find($request->primary_person_id);
        $secondary = Person::withTrashed()->find($request->secondary_person_id);
        $plan = $this->plan($primary->getKey(), $secondary->getKey());

        $differences = [];
        foreach (['full_name', 'kunyah', 'nisbah', 'birth_year_h', 'death_year_h', 'country_or_region'] as $field) {
            if ($primary->{$field} !== null && $secondary->{$field} !== null && (string) $primary->{$field} !== (string) $secondary->{$field}) {
                $differences[] = ['field' => $field, 'primary' => $primary->{$field}, 'secondary' => $secondary->{$field}];
            }
        }

        return [
            'repoint' => array_map(fn (array $op) => ['table' => $op['table'], 'column' => $op['column'], 'count' => count($op['ids'])], $plan['ops']),
            'skipped' => $plan['skipped'],
            'name_variants_to_add' => array_column($plan['variants'], 'name'),
            'preferred_claims_to_clear' => count($plan['clear_preferred']),
            'differences' => $differences,
            'primary_unchanged' => true,
        ];
    }

    // ------------------------------------------------------------------ الاعتماد والتنفيذ

    /** اعتماد صريح من مراجع، وينفَّذ الدمج في المعاملة نفسها. */
    public function approve(User $actor, PersonMergeRequest $request, ?string $notes = null): PersonMergeRequest
    {
        return DB::transaction(function () use ($actor, $request, $notes) {
            $locked = $this->lockPending($request);
            $this->assertNotRequester($actor, $locked);

            // قفل العلَمين بترتيب ثابت لتفادي التشابك
            $people = Person::withTrashed()->whereIn('id', [$locked->primary_person_id, $locked->secondary_person_id])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $primary = $people[$locked->primary_person_id] ?? null;
            $secondary = $people[$locked->secondary_person_id] ?? null;
            foreach ([$primary, $secondary] as $p) {
                if ($p === null || $p->trashed() || $p->merged_into_person_id !== null) {
                    throw ValidationException::withMessages(['request' => __('merges.person_unusable')]);
                }
            }

            $plan = $this->plan($primary->getKey(), $secondary->getKey());
            $counts = [];

            foreach ($plan['ops'] as $op) {
                foreach (array_chunk($op['ids'], self::CHUNK) as $chunk) {
                    DB::table($op['table'])->whereIn('id', $chunk)->where($op['column'], $secondary->getKey())->update([$op['column'] => $primary->getKey()]);
                    DB::table('person_merge_repoints')->insert(array_map(fn (int $id) => [
                        'merge_request_id' => $locked->getKey(), 'table_name' => $op['table'], 'row_id' => $id,
                        'column_name' => $op['column'], 'old_person_id' => $secondary->getKey(), 'created_at' => now(),
                    ], $chunk));
                }
                $counts["{$op['table']}.{$op['column']}"] = count($op['ids']);
            }

            if ($plan['clear_preferred'] !== []) {
                DB::table('person_date_claims')->whereIn('id', $plan['clear_preferred'])->update(['is_preferred' => false]);
            }

            $variantIds = [];
            foreach ($plan['variants'] as $variant) {
                $variantIds[] = DB::table('person_name_variants')->insertGetId([
                    'person_id' => $primary->getKey(), 'name' => $variant['name'], 'name_search' => $this->normalizer->normalize($variant['name']),
                    'variant_type' => $variant['type'], 'notes' => 'من علَم مدموج #'.$secondary->getKey(), 'created_at' => now(), 'updated_at' => now(),
                ]);
            }

            $secondary->forceFill(['merged_into_person_id' => $primary->getKey()])->save();

            $locked->forceFill([
                'status' => MergeRequestStatus::Executed, 'decided_by' => $actor->getKey(), 'decided_at' => now(),
                'decision_notes' => $notes !== null && trim($notes) !== '' ? trim($notes) : null, 'executed_at' => now(),
                'snapshot' => [
                    'primary' => $primary->only(['id', 'primary_name', 'full_name', 'kunyah', 'nisbah', 'birth_year_h', 'death_year_h']),
                    'secondary' => $secondary->only(['id', 'primary_name', 'full_name', 'kunyah', 'nisbah', 'birth_year_h', 'death_year_h']),
                    'secondary_merged_into_before' => null,
                    'created_variant_ids' => $variantIds,
                    'cleared_preferred_claim_ids' => $plan['clear_preferred'],
                    'counts' => $counts,
                    'skipped' => $plan['skipped'],
                ],
            ])->save();

            $this->log->record('merges.executed', $locked, ['primary_person_id' => $primary->getKey(), 'secondary_person_id' => $secondary->getKey(), 'counts' => $counts], $actor);

            return $locked->refresh();
        });
    }

    // ------------------------------------------------------------------ التراجع

    public function revert(User $actor, PersonMergeRequest $request, string $notes): PersonMergeRequest
    {
        if (trim($notes) === '') {
            throw ValidationException::withMessages(['notes' => __('merges.notes_required')]);
        }

        return DB::transaction(function () use ($actor, $request, $notes) {
            /** @var PersonMergeRequest $locked */
            $locked = PersonMergeRequest::query()->lockForUpdate()->findOrFail($request->getKey());
            if ($locked->status !== MergeRequestStatus::Executed || $locked->reverted_at !== null) {
                throw ValidationException::withMessages(['request' => __('merges.not_executed')]);
            }

            $people = Person::withTrashed()->whereIn('id', [$locked->primary_person_id, $locked->secondary_person_id])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $primary = $people[$locked->primary_person_id];
            $secondary = $people[$locked->secondary_person_id];
            if ($primary->merged_into_person_id !== null) {
                throw ValidationException::withMessages(['request' => __('merges.primary_merged_since')]);
            }
            if ((int) $secondary->merged_into_person_id !== (int) $primary->getKey()) {
                throw ValidationException::withMessages(['request' => __('merges.secondary_state_changed')]);
            }

            $restored = 0;
            $conflicts = [];
            $locked->repoints()->orderBy('id')->get()->groupBy(fn (PersonMergeRepoint $r) => $r->table_name.'|'.$r->column_name)->each(
                function ($rows, string $key) use ($primary, &$restored, &$conflicts) {
                    [$table, $column] = explode('|', $key);
                    foreach ($rows->chunk(self::CHUNK) as $chunk) {
                        // لا يُعاد إلا ما ما زال يشير إلى الأساسي؛ غيره عدّله أحد بعد الدمج فيُترك
                        $ids = $chunk->pluck('row_id')->all();
                        $still = DB::table($table)->whereIn('id', $ids)->where($column, $primary->getKey())->pluck('id')->all();
                        foreach ($chunk as $row) {
                            if (in_array($row->row_id, $still, true)) {
                                DB::table($table)->where('id', $row->row_id)->update([$column => $row->old_person_id]);
                                $restored++;
                            } else {
                                $conflicts[] = ['table' => $table, 'column' => $column, 'row_id' => $row->row_id];
                            }
                        }
                    }
                }
            );

            $snapshot = $locked->snapshot ?? [];
            DB::table('person_name_variants')->whereIn('id', $snapshot['created_variant_ids'] ?? [])->where('person_id', $primary->getKey())->delete();
            if (! empty($snapshot['cleared_preferred_claim_ids'])) {
                DB::table('person_date_claims')->whereIn('id', $snapshot['cleared_preferred_claim_ids'])->update(['is_preferred' => true]);
            }
            $secondary->forceFill(['merged_into_person_id' => $snapshot['secondary_merged_into_before'] ?? null])->save();

            $snapshot['revert'] = ['by' => $actor->getKey(), 'restored_rows' => $restored, 'conflicts' => $conflicts, 'notes' => trim($notes)];
            $locked->forceFill(['status' => MergeRequestStatus::Reverted, 'reverted_at' => now(), 'snapshot' => $snapshot])->save();
            $this->log->record('merges.reverted', $locked, ['restored_rows' => $restored, 'conflicts' => count($conflicts), 'notes' => trim($notes)], $actor);

            return $locked->refresh();
        });
    }

    // ------------------------------------------------------------------ الخطة (بلا كتابة)

    /**
     * @return array{ops:list<array{table:string,column:string,ids:list<int>}>,skipped:list<array<string,mixed>>,variants:list<array{name:string,type:string}>,clear_preferred:list<int>}
     */
    private function plan(int $primaryId, int $secondaryId): array
    {
        $ops = [];
        $skipped = [];

        foreach (self::TARGETS as [$table, $column]) {
            $ids = DB::table($table)->where($column, $secondaryId)->pluck('id')->map(fn ($i) => (int) $i)->all();
            if ($ids === []) {
                continue;
            }
            [$ids, $skip, $reason] = $this->filterConflicts($table, $column, $ids, $primaryId, $secondaryId);
            if ($skip !== []) {
                $skipped[] = ['table' => $table, 'column' => $column, 'reason' => $reason, 'row_ids' => $skip];
            }
            if ($ids !== []) {
                $ops[] = ['table' => $table, 'column' => $column, 'ids' => $ids];
            }
        }

        foreach (self::MORPH_TARGETS as $table => [$column, $typeColumn]) {
            $ids = DB::table($table)->where($typeColumn, (new Person)->getMorphClass())->where($column, $secondaryId)->pluck('id')->map(fn ($i) => (int) $i)->all();
            if ($ids === []) {
                continue;
            }
            [$ids, $skip, $reason] = $this->filterConflicts($table, $column, $ids, $primaryId, $secondaryId);
            if ($skip !== []) {
                $skipped[] = ['table' => $table, 'column' => $column, 'reason' => $reason, 'row_ids' => $skip];
            }
            if ($ids !== []) {
                $ops[] = ['table' => $table, 'column' => $column, 'ids' => $ids];
            }
        }

        // الإقرار المفضّل: لا يبقى اثنان من النوع نفسه للعلَم الواحد
        $clear = [];
        $moving = (collect($ops)->first(fn ($op) => $op['table'] === 'person_date_claims')['ids'] ?? []);
        if ($moving !== []) {
            $primaryPreferred = DB::table('person_date_claims')->where('person_id', $primaryId)->where('is_preferred', true)->pluck('claim_type')->all();
            $clear = DB::table('person_date_claims')->whereIn('id', $moving)->where('is_preferred', true)->whereIn('claim_type', $primaryPreferred)->pluck('id')->map(fn ($i) => (int) $i)->all();
        }

        return ['ops' => $ops, 'skipped' => $skipped, 'variants' => $this->variantsToAdd($primaryId, $secondaryId), 'clear_preferred' => $clear];
    }

    /** @return array{0:list<int>,1:list<int>,2:string|null} [الصالح، المتروك، السبب] */
    private function filterConflicts(string $table, string $column, array $ids, int $primaryId, int $secondaryId): array
    {
        $skip = [];
        $reason = null;

        if ($table === 'person_relations') {
            // يصير الشيخ تلميذ نفسه إن كان الطرف الآخر هو الأساسي (قيد قاعدة البيانات يمنعه)
            $other = $column === 'teacher_person_id' ? 'student_person_id' : 'teacher_person_id';
            $skip = DB::table($table)->whereIn('id', $ids)->where($other, $primaryId)->pluck('id')->map(fn ($i) => (int) $i)->all();
            $reason = 'تصير علاقة العلَم بنفسه';
        } elseif ($table === 'book_contributors') {
            $rows = DB::table($table)->whereIn('id', $ids)->get(['id', 'book_id', 'role']);
            foreach ($rows as $row) {
                if (DB::table($table)->where('book_id', $row->book_id)->where('person_id', $primaryId)->where('role', $row->role)->exists()) {
                    $skip[] = (int) $row->id;
                }
            }
            $reason = 'العلَم الأساسي مسجَّل أصلًا بالدور نفسه في الكتاب نفسه';
        } elseif ($table === 'attachables') {
            $rows = DB::table($table)->whereIn('id', $ids)->get(['id', 'attachment_id']);
            foreach ($rows as $row) {
                if (DB::table($table)->where('attachment_id', $row->attachment_id)->where('attachable_type', (new Person)->getMorphClass())->where('attachable_id', $primaryId)->exists()) {
                    $skip[] = (int) $row->id;
                }
            }
            $reason = 'المرفق مربوط أصلًا بالعلَم الأساسي';
        } elseif ($table === 'persons') {
            // علَم دُمج سابقًا في المدموج: يُحوَّل مباشرة إلى الأساسي (تسطيح السلسلة) ولا يُترك على وسيط
            $skip = [];
        }

        return [array_values(array_diff($ids, $skip)), $skip, $reason];
    }

    /** @return list<array{name:string,type:string}> أسماء العلَم المدموج التي لا يعرفها الأساسي بعد */
    private function variantsToAdd(int $primaryId, int $secondaryId): array
    {
        $primary = Person::withTrashed()->find($primaryId);
        $secondary = Person::withTrashed()->find($secondaryId);

        $known = DB::table('person_name_variants')->where('person_id', $primaryId)->pluck('name')->all();
        $known = array_merge($known, array_filter([$primary->primary_name, $primary->full_name, $primary->kunyah, $primary->nisbah]));
        $knownNormalized = array_flip(array_map(fn ($n) => $this->normalizer->normalize((string) $n), $known));

        $candidates = [
            [$secondary->primary_name, NameVariantType::Name], [$secondary->full_name, NameVariantType::Name],
            [$secondary->kunyah, NameVariantType::Kunyah], [$secondary->nisbah, NameVariantType::Nisbah],
        ];
        $out = [];
        foreach ($candidates as [$name, $type]) {
            $normalized = $this->normalizer->normalize((string) $name);
            if ($name !== null && $normalized !== '' && ! isset($knownNormalized[$normalized])) {
                $out[] = ['name' => $name, 'type' => $type->value];
                $knownNormalized[$normalized] = true;
            }
        }

        return $out;
    }

    // ------------------------------------------------------------------ مساعدات

    private function lockPending(PersonMergeRequest $request): PersonMergeRequest
    {
        /** @var PersonMergeRequest $locked */
        $locked = PersonMergeRequest::query()->lockForUpdate()->findOrFail($request->getKey());
        if ($locked->status !== MergeRequestStatus::Pending) {
            throw ValidationException::withMessages(['request' => __('merges.not_pending')]);
        }

        return $locked;
    }

    private function assertNotRequester(User $actor, PersonMergeRequest $request): void
    {
        if (! config('access.allow_self_approval') && (int) $request->requested_by === (int) $actor->getKey()) {
            throw ValidationException::withMessages(['request' => __('merges.self_approval')]);
        }
    }
}
