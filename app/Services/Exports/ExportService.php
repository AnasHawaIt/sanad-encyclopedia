<?php

namespace App\Services\Exports;

use App\Http\Controllers\Api\V1\AsnadController;
use App\Http\Resources\AsnadResource;
use App\Http\Resources\IjazaResource;
use App\Http\Resources\PersonResource;
use App\Models\Asnad;
use App\Models\Ijaza;
use App\Models\Person;
use App\Models\User;
use App\Services\Asnad\ChainCounter;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * يبني ملفات التصدير من السجلات التي يراها المستخدم نفسه فقط (visibleTo)،
 * والعلَم الذي لا يراه يظهر «غير متاح» لا اسمه. الحقول الداخلية (ملاحظات الباحثين) لا تدخل في CSV ولا النص،
 * وفي JSON تتبع قاعدة الواجهات العادية. لا يُصدَّر مؤرشَف ولا مدموج.
 */
class ExportService
{
    /** الحدود الافتراضية لعدد السجلات في الملف الواحد؛ تُضبط من config('access.export_limits') */
    public const LIMITS = ['asnads' => 500, 'persons' => 5000, 'ijazas' => 2000];

    public static function limit(string $kind): int
    {
        return (int) config("access.export_limits.{$kind}", self::LIMITS[$kind]);
    }

    public const HIDDEN_PERSON = '(علَم غير متاح)';

    public function __construct(private CsvWriter $csv, private ChainCounter $counter) {}

    // ------------------------------------------------------------------ الأسانيد

    /** @return array{items:Collection<int,Asnad>,total:int,truncated:bool} */
    public function asnads(User $user, Collection|array|null $ids, ?int $bookId, ?string $term): array
    {
        $query = Asnad::query()->visibleTo($user);
        if ($ids) {
            $query->whereKey($ids);
        }
        if ($bookId) {
            $query->whereHas('books', fn ($q) => $q->whereKey($bookId)->visibleTo($user));
        }
        if ($term) {
            $query->searchText($term);
        }

        return $this->limited($query->orderBy('id'), self::limit('asnads'), fn (Collection $c) => $c->map(fn (Asnad $a) => AsnadController::load($a, $user)));
    }

    /** @param Collection<int,Asnad> $asnads */
    public function asnadsJson(Collection $asnads, Request $request): array
    {
        return $asnads->map(fn (Asnad $a) => (new AsnadResource($a))->resolve($request))->values()->all();
    }

    public function asnadsCsv(Collection $asnads): string
    {
        $rows = $asnads->map(function (Asnad $a) {
            $counts = $this->counter->forAsnad($a);

            return [
                $a->id, $a->title, $a->type?->name, $a->verification_status->label(), $a->publication_status->label(),
                $this->chainLine($a), $counts['persons_in_chain'], $counts['intermediaries'], $counts['unique_persons'],
                (bool) $counts['complete'], $a->books->pluck('title')->implode('؛ '), $a->ijazas->pluck('ijaza_number')->filter()->implode('؛ '),
                $a->chain_text_original,
            ];
        });

        return $this->csv->write(
            ['الرقم', 'العنوان', 'النوع', 'التحقق', 'النشر', 'السلسلة (من الأقرب إلينا)', 'عدد الرجال', 'عدد الوسائط', 'الأعلام الفريدون', 'مكتمل', 'الكتب', 'الإجازات', 'النص الأصلي'],
            $rows,
        );
    }

    /** صف لكل حلقة: ينفع للفرز والمقارنة في Excel */
    public function asnadNodesCsv(Collection $asnads): string
    {
        $rows = [];
        foreach ($asnads as $a) {
            foreach ($a->nodes as $n) {
                $rows[] = [
                    $a->id, $a->title, $n->sequence_number, $this->nodeName($n), $n->person?->death_year_h,
                    $n->person_id === null ? 'غير محسوم' : ($n->person ? 'مربوط' : 'مربوط بعلَم غير متاح'),
                    $n->role?->name, $n->transmissionMode?->name, $n->text_segment,
                ];
            }
        }

        return $this->csv->write(['رقم السند', 'العنوان', 'الموضع', 'الاسم', 'سنة الوفاة (هـ)', 'الربط', 'الصفة', 'صيغة التحمل', 'مقطع النص'], $rows);
    }

    public function asnadsText(Collection $asnads): string
    {
        $blocks = $asnads->map(function (Asnad $a) {
            $counts = $this->counter->forAsnad($a);
            $lines = ["السند #{$a->id}".($a->title ? ": {$a->title}" : '')];
            $lines[] = 'الحالة: '.$a->verification_status->label().' / '.$a->publication_status->label();
            $lines[] = 'السلسلة (الموضع 1 هو الأقرب إلينا):';
            foreach ($a->nodes as $n) {
                $death = $n->person?->death_year_h ? " (ت {$n->person->death_year_h}هـ)" : '';
                $lines[] = "  {$n->sequence_number}. ".$this->nodeName($n).$death;
            }
            $lines[] = "عدد الرجال: {$counts['persons_in_chain']}، الوسائط: {$counts['intermediaries']}، الأعلام الفريدون: {$counts['unique_persons']}"
                .($counts['complete'] ? '' : ' (غير مكتمل: فيه حلقات غير محسومة)');
            if ($a->books->isNotEmpty()) {
                $lines[] = 'الكتب: '.$a->books->pluck('title')->implode('؛ ');
            }
            if ($a->chain_text_original) {
                $lines[] = 'النص الأصلي: '.$a->chain_text_original;
            }

            return implode("\n", $lines);
        });

        return $blocks->implode("\n\n".str_repeat('-', 40)."\n\n")."\n";
    }

    // ------------------------------------------------------------------ الأعلام

    /** @return array{items:Collection<int,Person>,total:int,truncated:bool} */
    public function persons(User $user, ?string $term, ?int $deathFrom, ?int $deathTo): array
    {
        $query = Person::query()->canonical()->visibleTo($user)->with(['nameVariants', 'dateClaims']);
        if ($term) {
            $query->searchName($term);
        }
        if ($deathFrom) {
            $query->where('death_year_h', '>=', $deathFrom);
        }
        if ($deathTo) {
            $query->where('death_year_h', '<=', $deathTo);
        }

        return $this->limited($query->orderBy('primary_name')->orderBy('id'), self::limit('persons'));
    }

    public function personsJson(Collection $persons, Request $request): array
    {
        return $persons->map(fn (Person $p) => (new PersonResource($p))->resolve($request))->values()->all();
    }

    public function personsCsv(Collection $persons): string
    {
        return $this->csv->write(
            ['الرقم', 'الاسم', 'الاسم الكامل', 'الكنية', 'النسبة', 'سنة الميلاد (هـ)', 'سنة الوفاة (هـ)', 'البلد/الإقليم', 'صيغ الاسم الأخرى', 'التحقق', 'النشر', 'ترجمة'],
            $persons->map(fn (Person $p) => [
                $p->id, $p->primary_name, $p->full_name, $p->kunyah, $p->nisbah, $p->birth_year_h, $p->death_year_h, $p->country_or_region,
                $p->nameVariants->pluck('name')->implode('؛ '), $p->verification_status->label(), $p->publication_status->label(), $p->biography,
            ]),
        );
    }

    // ------------------------------------------------------------------ الإجازات

    /** @return array{items:Collection<int,Ijaza>,total:int,truncated:bool} */
    public function ijazas(User $user, ?string $term, ?int $grantor, ?int $recipient): array
    {
        $visible = fn ($q) => $q->visibleTo($user);
        $query = Ijaza::query()->visibleTo($user)->with(['type', 'grantor' => $visible, 'recipient' => $visible, 'books' => $visible, 'asnads' => $visible]);
        if ($term) {
            $query->searchText($term);
        }
        if ($grantor) {
            $query->where('grantor_person_id', $grantor);
        }
        if ($recipient) {
            $query->where('recipient_person_id', $recipient);
        }

        return $this->limited($query->orderBy('id'), self::limit('ijazas'));
    }

    public function ijazasJson(Collection $ijazas, Request $request): array
    {
        return $ijazas->map(fn (Ijaza $i) => (new IjazaResource($i))->resolve($request))->values()->all();
    }

    public function ijazasCsv(Collection $ijazas): string
    {
        $name = fn (Ijaza $i, string $rel, string $col) => $i->{$rel}?->primary_name ?? ($i->{$col} ? self::HIDDEN_PERSON : null);

        return $this->csv->write(
            ['الرقم', 'رقم الإجازة', 'النوع', 'المجيز', 'المجاز له', 'السنة (هـ)', 'التاريخ الميلادي', 'المكان', 'التحقق', 'النشر', 'الكتب', 'الأسانيد', 'النص الأصلي', 'النص المصحَّح', 'ملخص'],
            $ijazas->map(fn (Ijaza $i) => [
                $i->id, $i->ijaza_number, $i->type?->name, $name($i, 'grantor', 'grantor_person_id'), $name($i, 'recipient', 'recipient_person_id'),
                $i->ijaza_year_h, $i->ijaza_date_gregorian?->format('Y-m-d'), $i->ijaza_place, $i->verification_status->label(), $i->publication_status->label(),
                $i->books->pluck('title')->implode('؛ '), $i->asnads->pluck('title')->implode('؛ '), $i->original_text, $i->revised_text, $i->summary,
            ]),
        );
    }

    // ------------------------------------------------------------------ مساعدات

    private function chainLine(Asnad $asnad): string
    {
        return $asnad->nodes->map(fn ($n) => $this->nodeName($n))->implode(' ← ');
    }

    private function nodeName($node): string
    {
        if ($node->person) {
            return $node->person->primary_name;
        }
        if ($node->person_id !== null) {
            return self::HIDDEN_PERSON;
        }

        return ($node->unresolved_name ?: '؟').' (غير محسوم)';
    }

    /**
     * @return array{items:Collection,total:int,truncated:bool}
     */
    private function limited($query, int $limit, ?callable $after = null): array
    {
        $total = (clone $query)->count();
        $items = $query->limit($limit)->get();
        if ($after) {
            $items = $after($items);
        }

        return ['items' => $items, 'total' => $total, 'truncated' => $total > $limit];
    }
}
