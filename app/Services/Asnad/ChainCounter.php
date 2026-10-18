<?php

namespace App\Services\Asnad;

use App\Models\Asnad;
use App\Models\AsnadNode;
use App\Models\Book;
use Illuminate\Support\Collection;

/**
 * المصدر الوحيد لكل أعداد الأسانيد (قرار ق1). لا تخزَّن هذه الأعداد في أي جدول، وكل مكان
 * (قائمة، تفاصيل، تصدير، مقارنة) يستدعي هذه الخدمة نفسها حتى لا يتعارض رقمان.
 *
 * sequence_number = 1 هو الطرف الأقرب إلينا، وآخر رقم هو الأقدم. حدّا العدّ (count_from_seq، count_to_seq)
 * فارغان افتراضيًا ويعنيان أول عنصر وآخر عنصر.
 */
class ChainCounter
{
    public function __construct(private PersonCanonicalizer $canonicalizer) {}

    /**
     * @param  Collection<int,AsnadNode>|null  $nodes  إن لم تُمرَّر تُقرأ من السند
     * @return array<string,mixed>
     */
    public function forAsnad(Asnad $asnad, ?Collection $nodes = null): array
    {
        $nodes = $this->nodesOf($asnad, $nodes);

        $summary = $this->summarize($asnad, $nodes, $this->canonicalizer->map($nodes->pluck('person_id')->filter()->all()));
        unset($summary['_resolved_ids']);

        return $summary;
    }

    /**
     * أعداد مجموعة أسانيد معًا: الأعلام الفريدون عبر المجموعة وعدد ظهور كل علَم.
     * العناصر غير المربوطة لا يمكن أن تُطابَق بغيرها، فالفريدون حدّ أدنى ويُعلَّم ذلك صراحة
     * (اتحاد الأعلام غير المحسوم لا يُفترض أبدًا).
     *
     * @param  Collection<int,Asnad>  $asnads  مع nodes محمّلة (أو تُحمَّل هنا)
     * @return array<string,mixed>
     */
    public function forSet(Collection $asnads): array
    {
        // قد تكون مجموعة عادية لا Eloquent، فنحمّل العناصر لكل سند لم تُحمَّل له
        $asnads->each(fn (Asnad $a) => $a->loadMissing('nodes'));
        $canon = $this->canonicalizer->map($asnads->flatMap(fn (Asnad $a) => $a->nodes->pluck('person_id'))->filter()->all());

        $perAsnad = [];
        $appearances = [];
        $unresolved = 0;
        $complete = true;
        $positions = 0;
        foreach ($asnads as $asnad) {
            $summary = $this->summarize($asnad, $this->nodesOf($asnad, null), $canon);
            $perAsnad[$asnad->getKey()] = $this->compact($summary);
            $complete = $complete && $summary['complete'];
            $unresolved += $summary['unresolved_positions'];
            $positions += $summary['persons_in_chain'] ?? 0;

            foreach ($summary['_resolved_ids'] as $personId) {
                $appearances[$personId]['appearances'] = ($appearances[$personId]['appearances'] ?? 0) + 1;
                $appearances[$personId]['asnad_ids'][$asnad->getKey()] = true;
            }
        }

        $rows = [];
        foreach ($appearances as $personId => $data) {
            $rows[] = [
                'person_id' => $personId,
                'appearances' => $data['appearances'],
                'asnads_count' => count($data['asnad_ids']),
            ];
        }
        usort($rows, fn ($a, $b) => [$b['appearances'], $a['person_id']] <=> [$a['appearances'], $b['person_id']]);

        return [
            'asnads_count' => $asnads->count(),
            'total_positions' => $positions,
            'unique_persons_in_set' => count($appearances),
            'unique_is_lower_bound' => $unresolved > 0,
            'unresolved_positions' => $unresolved,
            'complete' => $asnads->isNotEmpty() && $complete,
            'appearances' => $rows,
            'asnads' => $perAsnad,
            'definitions' => $this->definitions(),
        ];
    }

    /**
     * هل مؤلف الكتاب عنصر في السند؟ المؤلف لا يُضاف إلى السلسلة تلقائيًا ولا يُحسب مرتين:
     * هو داخل الأعداد فقط إن كان عنصرًا فعلًا.
     *
     * @param  Collection<int,Book>  $books
     * @return list<array<string,mixed>>
     */
    public function authorCheck(Asnad $asnad, Collection $books): array
    {
        $nodes = $this->nodesOf($asnad, null);
        $canon = $this->canonicalizer->map(
            $nodes->pluck('person_id')->merge($books->pluck('author_person_id'))->filter()->all()
        );
        $range = $this->range($asnad, $nodes);

        return $books->map(function (Book $book) use ($nodes, $canon, $range) {
            $authorId = $book->author_person_id ? $canon[(int) $book->author_person_id] ?? (int) $book->author_person_id : null;
            $positions = $authorId === null || ! $range['valid'] ? [] : $nodes
                ->filter(fn (AsnadNode $n) => $n->person_id !== null && ($canon[(int) $n->person_id] ?? null) === $authorId)
                ->filter(fn (AsnadNode $n) => $n->sequence_number >= $range['from'] && $n->sequence_number <= $range['to'])
                ->pluck('sequence_number')->values()->all();

            return [
                'book_id' => $book->getKey(),
                'author_person_id' => $book->author_person_id,
                'author_in_chain' => $positions !== [],
                'author_positions' => $positions,
            ];
        })->values()->all();
    }

    /** @return array<string,string> */
    public function definitions(): array
    {
        return __('asnad.definitions');
    }

    /** @return array{from:int|null,to:int|null,valid:bool,explicit:bool} */
    public function range(Asnad $asnad, Collection $nodes): array
    {
        $max = $nodes->max('sequence_number');
        $explicit = $asnad->count_from_seq !== null || $asnad->count_to_seq !== null;
        $from = $asnad->count_from_seq ?? ($max === null ? null : 1);
        $to = $asnad->count_to_seq ?? $max;
        $valid = $max !== null && $from !== null && $to !== null && $from >= 1 && $from <= $to && $to <= $max;

        return ['from' => $from, 'to' => $to, 'valid' => $valid, 'explicit' => $explicit];
    }

    /**
     * @param  array<int,int>  $canon
     * @return array<string,mixed>
     */
    private function summarize(Asnad $asnad, Collection $nodes, array $canon): array
    {
        $range = $this->range($asnad, $nodes);
        $inRange = $range['valid']
            ? $nodes->filter(fn (AsnadNode $n) => $n->sequence_number >= $range['from'] && $n->sequence_number <= $range['to'])->values()
            : collect();

        $resolved = $inRange->filter(fn (AsnadNode $n) => $n->person_id !== null);
        $unresolved = $inRange->count() - $resolved->count();
        $count = $inRange->count();

        $byPerson = [];
        foreach ($resolved as $node) {
            $byPerson[$canon[(int) $node->person_id] ?? (int) $node->person_id][] = $node->sequence_number;
        }
        $repeated = [];
        foreach ($byPerson as $personId => $seqs) {
            if (count($seqs) > 1) {
                $repeated[] = ['person_id' => $personId, 'appearances' => count($seqs), 'positions' => $seqs];
            }
        }

        $issues = [];
        if ($nodes->isEmpty()) {
            $issues[] = 'empty_chain';
        } elseif (! $range['valid']) {
            $issues[] = 'invalid_bounds';
        }
        if ($unresolved > 0) {
            $issues[] = 'unresolved_nodes';
        }

        return [
            'total_positions' => $nodes->count(),
            'range' => ['from' => $range['from'], 'to' => $range['to'], 'explicit' => $range['explicit']],
            // سند فارغ: صفر عناصر (لا null)؛ null فقط حين يكون حدّا العدّ نفسهما غير صالحين
            'persons_in_chain' => $range['valid'] || $nodes->isEmpty() ? $count : null,
            'intermediaries' => $range['valid'] && $count >= 2 ? $count - 2 : null,
            'unique_persons' => $range['valid'] || $nodes->isEmpty() ? count($byPerson) : null,
            'unique_is_lower_bound' => $unresolved > 0,
            'unresolved_positions' => $unresolved,
            'repeated_persons' => $repeated,
            'complete' => $issues === [],
            'issues' => array_map(fn (string $code) => ['code' => $code, 'message' => __("asnad.issues.{$code}")], $issues),
            // داخلي: كل ظهور بمعرّف أساسي (للمجموعات). يُحذف قبل الإرجاع للخارج.
            '_resolved_ids' => $resolved->map(fn (AsnadNode $n) => $canon[(int) $n->person_id] ?? (int) $n->person_id)->values()->all(),
        ];
    }

    /** شكل مختصر للقوائم: بلا التفاصيل الثقيلة ولا المفاتيح الداخلية. */
    public function compact(array $summary): array
    {
        return [
            'persons_in_chain' => $summary['persons_in_chain'],
            'intermediaries' => $summary['intermediaries'],
            'unique_persons' => $summary['unique_persons'],
            'unresolved_positions' => $summary['unresolved_positions'],
            'complete' => $summary['complete'],
        ];
    }

    /** @return Collection<int,AsnadNode> */
    private function nodesOf(Asnad $asnad, ?Collection $nodes): Collection
    {
        $nodes ??= $asnad->relationLoaded('nodes') ? $asnad->nodes : $asnad->nodes()->get();

        return $nodes->sortBy('sequence_number')->values();
    }
}
