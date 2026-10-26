<?php

namespace App\Services\Asnad;

use App\Models\Asnad;
use App\Models\AsnadNode;
use App\Models\Person;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * مقارنة الأسانيد وبناء شجرتها. تقرأ فقط ولا تكتب شيئًا.
 *
 * قواعد المطابقة (قرار: لا دمج تلقائي):
 *  - عنصران يتطابقان فقط إذا ربطا بالعلَم الأساسي نفسه (بعد اتباع الدمج).
 *  - عنصر غير مربوط، أو مربوط بعلَم لا يراه المستخدم، لا يُطابَق مع أي عنصر آخر مهما تطابق الاسم المكتوب.
 *    فنتائج المقارنة حدّ أدنى للتطابق، وتُعلَن بحقل partial.
 *  - تُقارَن العناصر داخل حدّي العدّ لكل سند (ما لم يُطلب scope=full).
 *
 * الترتيب: رقم الموضع 1 هو الأقرب إلينا. الشجرة تبدأ من الأقدم.
 */
class AsnadComparator
{
    public const SCOPE_COUNTED = 'counted';

    public const SCOPE_FULL = 'full';

    public function __construct(private ChainCounter $counter, private PersonCanonicalizer $canonicalizer) {}

    /**
     * @param  Collection<int,Asnad>  $asnads  مع nodes محمّلة
     * @return array<string,mixed>
     */
    public function compare(Collection $asnads, ?User $viewer, string $scope = self::SCOPE_COUNTED): array
    {
        $chains = $this->chains($asnads, $viewer, $scope);
        $people = $chains['people'];
        $ids = $asnads->pluck('id')->all();
        $total = count($ids);

        // علَم => [asnad_id => [positions]]
        $occurrence = [];
        foreach ($chains['chains'] as $asnadId => $entries) {
            foreach ($entries as $entry) {
                if ($entry['kind'] === 'person') {
                    $occurrence[$entry['person_id']][$asnadId][] = $entry['sequence_number'];
                }
            }
        }

        $common = [];
        $shared = [];
        foreach ($occurrence as $personId => $byAsnad) {
            $all = array_merge(...array_values($byAsnad));
            $row = [
                'person' => $people[$personId],
                'asnad_ids' => array_keys($byAsnad),
                'positions' => $byAsnad,
                'mean_position' => round(array_sum($all) / count($all), 2),
            ];
            if ($total >= 2 && count($byAsnad) === $total) {
                $common[] = $row;
            } elseif (count($byAsnad) >= 2) {
                $shared[] = $row;
            }
        }
        $byMean = fn (array $a, array $b) => [$a['mean_position'], $a['person']['id']] <=> [$b['mean_position'], $b['person']['id']];
        usort($common, $byMean);
        usort($shared, $byMean);

        $pairs = [];
        for ($i = 0; $i < $total; $i++) {
            for ($j = $i + 1; $j < $total; $j++) {
                $a = $ids[$i];
                $b = $ids[$j];
                $both = array_keys(array_filter($occurrence, fn (array $by) => isset($by[$a], $by[$b])));
                $pairs[] = ['a' => $a, 'b' => $b, 'shared_persons' => count($both), 'person_ids' => array_values($both)];
            }
        }

        $summaries = [];
        $partial = false;
        foreach ($asnads as $asnad) {
            $entries = $chains['chains'][$asnad->id];
            $onlyHere = count(array_filter($occurrence, fn (array $by) => array_keys($by) === [$asnad->id]));
            $partial = $partial || $chains['partial'][$asnad->id];
            $summaries[] = [
                'id' => $asnad->id,
                'title' => $asnad->title,
                'counts' => $this->counter->compact($this->counter->forAsnad($asnad)),
                'chain' => array_map(fn (array $e) => $this->presentEntry($e, $people), $entries),
                'persons_only_here' => $onlyHere,
            ];
        }

        return [
            'asnads_count' => $total,
            'scope' => $scope,
            'asnads' => $summaries,
            'common_persons' => $common,
            'shared_persons' => $shared,
            'nearest_common' => $common[0] ?? null,
            'farthest_common' => $common === [] ? null : $common[count($common) - 1],
            'pairs' => $pairs,
            'partial' => $partial,
            'hidden_nodes' => $chains['hidden'],
            'definitions' => __('asnad.compare'),
        ];
    }

    /**
     * شجرة مسطّحة (لا متداخلة) كي لا يتجاوز عمق JSON حدّه في سلاسل طويلة. الأب يسبق أبناءه في المصفوفة.
     *
     * @param  Collection<int,Asnad>  $asnads  مع nodes محمّلة
     * @return array<string,mixed>
     */
    public function tree(Collection $asnads, ?User $viewer, string $scope = self::SCOPE_COUNTED): array
    {
        $chains = $this->chains($asnads, $viewer, $scope);
        $people = $chains['people'];

        $nodes = [];
        $children = [];
        $nextId = 1;
        $partial = false;
        $merged = 0;

        foreach ($asnads as $asnad) {
            $entries = array_reverse($chains['chains'][$asnad->id]); // الأقدم أولًا
            $partial = $partial || $chains['partial'][$asnad->id];
            $parent = 0;
            $lastIndex = count($entries) - 1;
            foreach ($entries as $i => $entry) {
                $childKey = $parent.'>'.$entry['key'];
                if (! isset($children[$childKey])) {
                    $id = $nextId++;
                    $children[$childKey] = $id;
                    $nodes[$id] = $this->presentEntry($entry, $people) + [
                        'id' => $id, 'parent_id' => $parent === 0 ? null : $parent,
                        'depth' => $i, 'asnad_ids' => [], 'ends_asnad_ids' => [],
                    ];
                } elseif ($entry['kind'] === 'person' && ! in_array($asnad->id, $nodes[$children[$childKey]]['asnad_ids'], true)) {
                    $merged++;
                }
                $id = $children[$childKey];
                $nodes[$id]['asnad_ids'][] = $asnad->id;
                if ($i === $lastIndex) {
                    $nodes[$id]['ends_asnad_ids'][] = $asnad->id;
                }
                $parent = $id;
            }
        }

        $list = array_values($nodes);

        return [
            'asnads_count' => $asnads->count(),
            'scope' => $scope,
            'asnads' => $asnads->map(fn (Asnad $a) => ['id' => $a->id, 'title' => $a->title, 'length' => count($chains['chains'][$a->id])])->values()->all(),
            'nodes' => $list,
            'stats' => [
                'nodes' => count($list),
                'roots' => count(array_filter($list, fn (array $n) => $n['parent_id'] === null)),
                'leaves' => count(array_filter($list, fn (array $n) => $n['ends_asnad_ids'] !== [])),
                'merged_appearances' => $merged,
            ],
            'partial' => $partial,
            'hidden_nodes' => $chains['hidden'],
            'definitions' => __('asnad.compare'),
        ];
    }

    /**
     * يحوّل كل سند إلى قائمة عناصر مطابَقة بمفتاح. الأعلام المرئية فقط تأخذ مفتاح العلَم.
     *
     * @param  Collection<int,Asnad>  $asnads
     * @return array{chains:array<int,list<array<string,mixed>>>,people:array<int,array<string,mixed>>,partial:array<int,bool>,hidden:int}
     */
    private function chains(Collection $asnads, ?User $viewer, string $scope): array
    {
        $asnads->each(fn (Asnad $a) => $a->loadMissing('nodes'));

        $rawIds = $asnads->flatMap(fn (Asnad $a) => $a->nodes->pluck('person_id'))->filter()->unique()->all();
        $canon = $this->canonicalizer->map($rawIds);
        $visible = $rawIds === [] ? collect() : Person::query()->visibleTo($viewer)
            ->whereIn('id', array_values(array_unique($canon)))
            ->get(['id', 'primary_name', 'death_year_h'])->keyBy('id');

        $people = [];
        $chains = [];
        $partial = [];
        $hidden = 0;

        foreach ($asnads as $asnad) {
            $nodes = $asnad->nodes->sortBy('sequence_number')->values();
            $range = $this->counter->range($asnad, $nodes);
            if ($scope === self::SCOPE_COUNTED) {
                $nodes = $range['valid']
                    ? $nodes->filter(fn (AsnadNode $n) => $n->sequence_number >= $range['from'] && $n->sequence_number <= $range['to'])->values()
                    : collect();
            }

            $entries = [];
            $isPartial = $scope === self::SCOPE_COUNTED && ! $range['valid'] && $asnad->nodes->isNotEmpty();
            foreach ($nodes as $node) {
                $personId = $node->person_id === null ? null : ($canon[(int) $node->person_id] ?? (int) $node->person_id);
                $unique = "x:{$asnad->id}:{$node->sequence_number}";
                if ($personId !== null && $visible->has($personId)) {
                    $p = $visible[$personId];
                    $people[$personId] = ['id' => $p->id, 'primary_name' => $p->primary_name, 'death_year_h' => $p->death_year_h];
                    $entries[] = ['sequence_number' => $node->sequence_number, 'key' => "p:{$personId}", 'kind' => 'person', 'person_id' => $personId, 'label' => $p->primary_name];
                } elseif ($personId !== null) {
                    $hidden++;
                    $isPartial = true;
                    $entries[] = ['sequence_number' => $node->sequence_number, 'key' => $unique, 'kind' => 'hidden', 'person_id' => null, 'label' => null];
                } else {
                    $isPartial = true;
                    $entries[] = ['sequence_number' => $node->sequence_number, 'key' => $unique, 'kind' => 'unresolved', 'person_id' => null, 'label' => $node->unresolved_name];
                }
            }
            $chains[$asnad->id] = $entries;
            $partial[$asnad->id] = $isPartial;
        }

        return ['chains' => $chains, 'people' => $people, 'partial' => $partial, 'hidden' => $hidden];
    }

    /**
     * @param  array<string,mixed>  $entry
     * @param  array<int,array<string,mixed>>  $people
     * @return array<string,mixed>
     */
    private function presentEntry(array $entry, array $people): array
    {
        return [
            'sequence_number' => $entry['sequence_number'],
            'kind' => $entry['kind'],
            'person' => $entry['person_id'] === null ? null : $people[$entry['person_id']],
            'unresolved_name' => $entry['kind'] === 'unresolved' ? $entry['label'] : null,
        ];
    }
}
