<?php

namespace App\Services\Asnad;

use App\Models\Asnad;
use App\Models\AsnadNode;
use App\Models\User;
use App\Services\Audit\ActivityLogger;
use App\Services\Concerns\SyncsChildren;
use App\Services\Persons\PersonAccess;
use App\Services\Search\ArabicNormalizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * السند قائمة مرتبة من العناصر (sequence_number = 1 هو الأقرب إلينا).
 *
 * القيد الفريد على (asnad_id, sequence_number) يمنع تبديل موضعين في تحديث واحد، لذلك كل تغيير في
 * الترتيب (إدراج، حذف، تبديل، نقل) يمرّ بالدالة renumber: إزاحة كل العناصر إلى مدى مؤقت بعيد ثم إسناد
 * الأرقام النهائية، داخل معاملة واحدة وبعد قفل صف السند فلا يتداخل طلبان.
 */
class AsnadService
{
    use SyncsChildren;

    public const MAX_NODES = 500;

    /** مدى مؤقت لا يتداخل مع أي ترقيم حقيقي (الأقصى 500) ولا يتجاوز حدّ العمود (65535). */
    private const PARK_OFFSET = 30000;

    private const NEW_NODE_PARK = 60000;

    private const FIELDS = ['title', 'sanad_type_id', 'count_from_seq', 'count_to_seq', 'notes'];

    private const NODE_FIELDS = ['person_id', 'unresolved_name', 'text_segment', 'chain_role_id', 'transmission_mode_id', 'notes'];

    public function __construct(
        private ActivityLogger $log,
        private ArabicNormalizer $normalizer,
        private PersonAccess $access,
        private ChainCounter $counter,
    ) {}

    public function create(User $actor, array $data): Asnad
    {
        $nodes = $data['nodes'] ?? [];
        $this->assertNodeCount(count($nodes));
        $this->assertPeopleUsable($actor, array_column($nodes, 'person_id'), 'nodes');

        return DB::transaction(function () use ($actor, $data, $nodes) {
            $asnad = new Asnad(array_intersect_key($data, array_flip(self::FIELDS)));
            $asnad->chain_text_original = $data['chain_text_original'] ?? null;
            $this->fillSearch($asnad);
            $asnad->created_by = $actor->getKey();
            $asnad->updated_by = $actor->getKey();
            $asnad->save();

            foreach (array_values($nodes) as $index => $node) {
                $asnad->nodes()->create($this->nodeAttributes($node) + [
                    'sequence_number' => $index + 1,
                    'created_by' => $actor->getKey(),
                ]);
            }
            $this->assertBounds($asnad->refresh());

            $this->log->record('asnads.created', $asnad, ['title' => $asnad->title, 'nodes' => count($nodes)], $actor);

            return $this->loaded($asnad);
        });
    }

    public function update(User $actor, Asnad $asnad, array $data): Asnad
    {
        return DB::transaction(function () use ($actor, $asnad, $data) {
            $asnad = $this->lock($asnad);

            if (array_key_exists('chain_text_original', $data)) {
                $this->assertOriginalNotChanged($asnad->chain_text_original, $data['chain_text_original'], 'chain_text_original');
            }

            $asnad->fill(array_intersect_key($data, array_flip(self::FIELDS)));
            if (array_key_exists('chain_text_original', $data) && blank($asnad->chain_text_original)) {
                $asnad->chain_text_original = $data['chain_text_original'];
            }
            $this->fillSearch($asnad);
            $asnad->updated_by = $actor->getKey();
            $this->assertBounds($asnad);
            $asnad->save();

            $changes = $this->diff($asnad, ['title_search', 'chain_text_search']);
            if ($changes !== []) {
                $this->log->record('asnads.updated', $asnad, $changes, $actor);
            }

            return $this->loaded($asnad->refresh());
        });
    }

    /** السند المرتبط بكتب أو إجازات لا يُؤرشف: كان ذلك سيترك الكتاب أو الإجازة تشير إلى سند مخفي. */
    public function archive(User $actor, Asnad $asnad): void
    {
        $usage = array_filter([
            'books' => DB::table('book_asnad')->where('asnad_id', $asnad->getKey())->count(),
            'ijazas' => DB::table('ijaza_asnad')->where('asnad_id', $asnad->getKey())->count(),
        ]);
        if ($usage !== []) {
            $details = collect($usage)->map(fn (int $n, string $k) => __("asnad.links.{$k}").": {$n}")->implode('، ');

            throw ValidationException::withMessages(['asnad' => __('asnad.asnad_linked', ['details' => $details])]);
        }

        DB::transaction(function () use ($actor, $asnad) {
            $asnad->delete();
            $this->log->record('asnads.archived', $asnad, ['title' => $asnad->title], $actor);
        });
    }

    public function restore(User $actor, Asnad $asnad): Asnad
    {
        return DB::transaction(function () use ($actor, $asnad) {
            $asnad->restore();
            $this->log->record('asnads.restored', $asnad, ['title' => $asnad->title], $actor);

            return $this->loaded($asnad->refresh());
        });
    }

    // ---------------------------------------------------------------- العناصر

    /** يضيف عنصرًا في الموضع المطلوب (أو في آخر السند) ويزيح ما بعده. */
    public function addNode(User $actor, Asnad $asnad, array $data): AsnadNode
    {
        $this->assertPeopleUsable($actor, [$data['person_id'] ?? null], 'person_id');

        return DB::transaction(function () use ($actor, $asnad, $data) {
            $asnad = $this->lock($asnad);
            $ids = $this->orderedIds($asnad);
            $this->assertNodeCount(count($ids) + 1);

            $position = $data['position'] ?? (count($ids) + 1);
            if ($position < 1 || $position > count($ids) + 1) {
                throw ValidationException::withMessages(['position' => __('asnad.bad_position', ['max' => count($ids) + 1])]);
            }

            $node = $asnad->nodes()->create($this->nodeAttributes($data) + [
                'sequence_number' => self::NEW_NODE_PARK,
                'created_by' => $actor->getKey(),
            ]);
            array_splice($ids, $position - 1, 0, [$node->getKey()]);
            $this->renumber($asnad, $ids);

            $this->log->record('asnads.node_added', $asnad, ['node_id' => $node->getKey(), 'position' => $position], $actor);

            return $node->refresh();
        });
    }

    public function updateNode(User $actor, Asnad $asnad, AsnadNode $node, array $data): AsnadNode
    {
        $this->assertPeopleUsable($actor, [$data['person_id'] ?? null], 'person_id');

        return DB::transaction(function () use ($actor, $asnad, $node, $data) {
            $asnad = $this->lock($asnad);
            $node = $asnad->nodes()->findOrFail($node->getKey());

            $node->fill(array_intersect_key($data, array_flip(self::NODE_FIELDS)));
            if ($node->person_id === null && blank($node->unresolved_name)) {
                throw ValidationException::withMessages(['person_id' => __('asnad.node_needs_identity')]);
            }
            $node->save();

            $changes = $this->diff($node);
            if ($changes !== []) {
                $this->log->record('asnads.node_updated', $asnad, ['node_id' => $node->getKey()] + $changes, $actor);
            }

            return $node->refresh();
        });
    }

    /** يحذف العنصر ويغلق الفجوة. لا يُسمح بحذف يُخرج حدّ العدّ الصريح عن السند: يُعدَّل الحدّ أولًا. */
    public function removeNode(User $actor, Asnad $asnad, AsnadNode $node): void
    {
        DB::transaction(function () use ($actor, $asnad, $node) {
            $asnad = $this->lock($asnad);
            $node = $asnad->nodes()->findOrFail($node->getKey());

            $remaining = $this->orderedIds($asnad);
            $remaining = array_values(array_filter($remaining, fn ($id) => $id !== $node->getKey()));
            $this->assertBoundsFit($asnad, count($remaining), 'bounds_break_on_remove');

            $node->delete();
            $this->renumber($asnad, $remaining);
            $this->log->record('asnads.node_removed', $asnad, ['node_id' => $node->getKey(), 'position' => $node->sequence_number], $actor);
        });
    }

    /**
     * إعادة ترتيب كاملة: القائمة يجب أن تضم كل عناصر السند مرة واحدة بالضبط. تبديل عنصرين
     * هو إرسال الترتيب بعد التبديل. حدّا العدّ مواضع، فلا يتغيران.
     *
     * @param  list<int>  $order
     */
    public function reorder(User $actor, Asnad $asnad, array $order): void
    {
        DB::transaction(function () use ($actor, $asnad, $order) {
            $asnad = $this->lock($asnad);
            $current = $this->orderedIds($asnad);

            $given = array_map('intval', $order);
            $same = count($given) === count($current) && count(array_unique($given)) === count($given)
                && array_diff($given, $current) === [];
            if (! $same) {
                throw ValidationException::withMessages(['order' => __('asnad.order_mismatch')]);
            }

            if ($given === $current) {
                return;
            }
            $this->renumber($asnad, $given);
            $this->log->record('asnads.reordered', $asnad, ['from' => $current, 'to' => $given], $actor);
        });
    }

    public function moveNode(User $actor, Asnad $asnad, AsnadNode $node, int $position): void
    {
        DB::transaction(function () use ($actor, $asnad, $node, $position) {
            $asnad = $this->lock($asnad);
            $ids = $this->orderedIds($asnad);
            if (! in_array($node->getKey(), $ids, true)) {
                throw ValidationException::withMessages(['node' => __('asnad.node_not_found')]);
            }
            if ($position < 1 || $position > count($ids)) {
                throw ValidationException::withMessages(['position' => __('asnad.bad_position', ['max' => count($ids)])]);
            }

            $without = array_values(array_filter($ids, fn ($id) => $id !== $node->getKey()));
            array_splice($without, $position - 1, 0, [$node->getKey()]);
            if ($without === $ids) {
                return;
            }
            $this->renumber($asnad, $without);
            $this->log->record('asnads.node_moved', $asnad, ['node_id' => $node->getKey(), 'to' => $position], $actor);
        });
    }

    // ---------------------------------------------------------------- داخلي

    /** قفل صف السند يجعل عمليات الترتيب المتزامنة تتسلسل بدل أن تتداخل. */
    private function lock(Asnad $asnad): Asnad
    {
        return Asnad::withTrashed()->whereKey($asnad->getKey())->lockForUpdate()->firstOrFail();
    }

    /** @return list<int> معرّفات العناصر بترتيبها الحالي */
    private function orderedIds(Asnad $asnad): array
    {
        return $asnad->nodes()->reorder('sequence_number')->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /** @param list<int> $ids الترتيب النهائي المطلوب، من 1 */
    private function renumber(Asnad $asnad, array $ids): void
    {
        // العنصر الجديد مركون أصلًا في مدى بعيد (NEW_NODE_PARK) فلا يُزاح ثانية، وإلا تجاوز حدّ العمود
        DB::table('asnad_nodes')->where('asnad_id', $asnad->getKey())->whereIn('id', $ids)
            ->where('sequence_number', '<', self::PARK_OFFSET)->update([
                'sequence_number' => DB::raw('sequence_number + '.self::PARK_OFFSET),
            ]);
        foreach ($ids as $index => $id) {
            DB::table('asnad_nodes')->where('id', $id)->update(['sequence_number' => $index + 1]);
        }
    }

    private function fillSearch(Asnad $asnad): void
    {
        $asnad->title_search = $this->normalizer->normalize($asnad->title);
        $asnad->chain_text_search = $this->normalizer->normalize($asnad->chain_text_original);
    }

    /** النص الأصلي لا يُعدَّل بعد حفظه؛ ملء حقل فارغ ليس تعديلًا. */
    private function assertOriginalNotChanged(?string $current, ?string $incoming, string $field): void
    {
        if (filled($current) && $incoming !== $current) {
            throw ValidationException::withMessages([$field => __('asnad.original_locked')]);
        }
    }

    private function assertNodeCount(int $count): void
    {
        if ($count > self::MAX_NODES) {
            throw ValidationException::withMessages(['nodes' => __('asnad.chain_too_long', ['max' => self::MAX_NODES])]);
        }
    }

    /** @param array<int,mixed> $ids */
    private function assertPeopleUsable(User $actor, array $ids, string $field): void
    {
        $this->access->assertUsable($actor, $ids, $field);
    }

    private function assertBounds(Asnad $asnad): void
    {
        $this->assertBoundsFit($asnad, $asnad->nodes()->count(), null);
    }

    private function assertBoundsFit(Asnad $asnad, int $nodeCount, ?string $messageKey): void
    {
        $from = $asnad->count_from_seq;
        $to = $asnad->count_to_seq;
        if ($from === null && $to === null) {
            return;
        }
        if ($nodeCount === 0) {
            throw ValidationException::withMessages(['count_from_seq' => __($messageKey ? "asnad.{$messageKey}" : 'asnad.bounds_without_nodes')]);
        }

        $low = $from ?? 1;
        $high = $to ?? $nodeCount;
        if ($low < 1 || $high < $low || $high > $nodeCount) {
            throw ValidationException::withMessages([
                $to !== null && $to > $nodeCount ? 'count_to_seq' : 'count_from_seq' => $messageKey
                    ? __("asnad.{$messageKey}")
                    : __('asnad.bounds_invalid', ['max' => $nodeCount]),
            ]);
        }
    }

    /** @param array<string,mixed> $node */
    private function nodeAttributes(array $node): array
    {
        $attributes = array_intersect_key($node, array_flip(self::NODE_FIELDS));
        if (($attributes['person_id'] ?? null) === null && blank($attributes['unresolved_name'] ?? null)) {
            throw ValidationException::withMessages(['nodes' => __('asnad.node_needs_identity')]);
        }

        return $attributes;
    }

    private function loaded(Asnad $asnad): Asnad
    {
        return $asnad->load(['nodes', 'type']);
    }
}
