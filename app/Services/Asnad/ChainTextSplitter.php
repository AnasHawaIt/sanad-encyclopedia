<?php

namespace App\Services\Asnad;

use App\Models\Person;
use App\Models\TransmissionMode;
use App\Models\User;
use App\Services\Search\ArabicNormalizer;

/**
 * يقترح تقسيم نص سند كامل إلى عناصر. اقتراح فقط: لا يكتب في قاعدة البيانات ولا يربط عنصرًا بعلَم
 * ولا يعتمد شيئًا. التقسيم بالكلمات المفتاحية (حدثنا، أخبرنا، عن…) يخطئ مع الأسماء المركبة والجمل الاعتراضية،
 * لذلك يعيد كل مقطع بنصه الحرفي، ويقترح مرشحين من الأعلام المرئيين للمراجعة اليدوية.
 *
 * الكلمة المفتاحية التي تسبق المقطع تُعاد في preceding_mode كما وردت. ولا يُقرَّر هنا إلى أي عنصر
 * تنتسب طريقة التحمّل، فذلك قرار الباحث عند الحفظ.
 */
class ChainTextSplitter
{
    /** كلمات تحمّل شائعة تُضاف إلى ما في جدول طرق التحمّل (الأطول أولًا حتى لا تُقطع «أخبرنا» عند «أخبر»). */
    private const BASELINE = [
        'حدثنا', 'حدثني', 'أخبرنا', 'أخبرني', 'أنبأنا', 'أنبأني', 'سمعت', 'قرأت على', 'قرأنا على',
        'أجازنا', 'أجازني', 'أجاز لي', 'أجاز لنا', 'أخبرتنا', 'حدثتنا', 'عن',
    ];

    private const MAX_SEGMENTS = 500;

    private const CANDIDATES_PER_SEGMENT = 3;

    public function __construct(private ArabicNormalizer $normalizer) {}

    /** @return array{note:string,segments:list<array<string,mixed>>} */
    public function suggest(string $text, ?User $viewer): array
    {
        $keywords = $this->keywords();
        $parts = $this->split($text, $keywords);

        $segments = [];
        foreach (array_slice($parts, 0, self::MAX_SEGMENTS) as $index => $part) {
            $segments[] = [
                'sequence_number' => $index + 1,
                'text_segment' => $part['text'],
                'unresolved_name' => $part['text'],
                'preceding_mode' => $part['mode'],
                'transmission_mode_id' => $part['mode'] === null ? null : ($keywords[$this->normalizer->normalize($part['mode'])] ?? null),
                'candidates' => $this->candidates($part['text'], $viewer),
            ];
        }

        return ['note' => __('asnad.split.note'), 'segments' => $segments];
    }

    /**
     * @param  array<string,int|null>  $keywords  كلمة مطبَّعة => معرّف طريقة التحمّل إن وُجدت في الجدول
     * @return list<array{mode:string|null,text:string}>
     */
    private function split(string $text, array $keywords): array
    {
        $words = preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $sorted = array_keys($keywords);
        usort($sorted, fn ($a, $b) => substr_count($b, ' ') <=> substr_count($a, ' '));

        $parts = [];
        $mode = null;
        $buffer = [];
        $flush = function () use (&$parts, &$mode, &$buffer): void {
            $segment = preg_replace('/^[\s،,.;:؛]+|[\s،,.;:؛]+$/u', '', implode(' ', $buffer));
            if ($segment !== '') {
                $parts[] = ['mode' => $mode, 'text' => $segment];
            }
            $buffer = [];
        };

        $count = count($words);
        for ($i = 0; $i < $count; $i++) {
            $matched = null;
            foreach ($sorted as $keyword) {
                $length = substr_count($keyword, ' ') + 1;
                if ($i + $length > $count) {
                    continue;
                }
                $candidate = $this->normalizer->normalize(implode(' ', array_slice($words, $i, $length)));
                if ($candidate === $keyword) {
                    $matched = [$length, implode(' ', array_slice($words, $i, $length))];
                    break;
                }
            }

            if ($matched !== null) {
                $flush();
                $mode = $matched[1];
                $i += $matched[0] - 1;

                continue;
            }
            $buffer[] = $words[$i];
        }
        $flush();

        return $parts;
    }

    /** @return array<string,int|null> */
    private function keywords(): array
    {
        $keywords = [];
        foreach (self::BASELINE as $word) {
            $keywords[$this->normalizer->normalize($word)] = null;
        }
        TransmissionMode::query()->active()->get(['id', 'name'])->each(function (TransmissionMode $mode) use (&$keywords) {
            $keywords[$this->normalizer->normalize($mode->name)] = $mode->getKey();
        });

        return array_filter($keywords, fn ($v, $k) => $k !== '', ARRAY_FILTER_USE_BOTH);
    }

    /** @return list<array{id:int,primary_name:string,death_year_h:int|null}> مرشحون مرئيون فقط؛ لا يُربط شيء تلقائيًا */
    private function candidates(string $segment, ?User $viewer): array
    {
        if ($this->normalizer->tokens($segment) === []) {
            return [];
        }

        return Person::query()->canonical()->visibleTo($viewer)->searchName($segment)
            ->orderBy('primary_name')->orderBy('id')->limit(self::CANDIDATES_PER_SEGMENT)
            ->get(['id', 'primary_name', 'death_year_h'])
            ->map(fn (Person $p) => ['id' => $p->id, 'primary_name' => $p->primary_name, 'death_year_h' => $p->death_year_h])
            ->all();
    }
}
