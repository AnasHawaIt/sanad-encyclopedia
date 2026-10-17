<?php

namespace App\Services\Sources;

use App\Models\Source;
use App\Models\User;
use App\Services\Audit\ActivityLogger;
use App\Services\Concerns\SyncsChildren;
use App\Services\Search\ArabicNormalizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SourceService
{
    use SyncsChildren;

    public function __construct(private ActivityLogger $log, private ArabicNormalizer $normalizer) {}

    public function create(User $actor, array $data): Source
    {
        return DB::transaction(function () use ($actor, $data) {
            $source = Source::create($this->withSearch($data) + ['created_by' => $actor->getKey()]);
            $this->log->record('sources.created', $source, ['title' => $source->title], $actor);

            // refresh: قيم DB الافتراضية (مثل source_type) لا تكون على النسخة قبل إعادة التحميل
            return $source->refresh()->loadCount('references');
        });
    }

    public function update(User $actor, Source $source, array $data): Source
    {
        return DB::transaction(function () use ($actor, $source, $data) {
            $source->fill($this->withSearch($data))->save();
            $changes = $this->diff($source, ['title_search', 'author_search']);
            if ($changes !== []) {
                $this->log->record('sources.updated', $source, $changes, $actor);
            }

            return $source->refresh()->loadCount('references');
        });
    }

    /** مصدر تستند إليه مراجع لا يُؤرشف: كان ذلك سيُخفي أصل مراجع قائمة. */
    public function archive(User $actor, Source $source): void
    {
        $count = $source->references()->count();
        if ($count > 0) {
            throw ValidationException::withMessages(['source' => __('records.source_in_use', ['count' => $count])]);
        }

        DB::transaction(function () use ($actor, $source) {
            $source->delete();
            $this->log->record('sources.archived', $source, ['title' => $source->title], $actor);
        });
    }

    public function restore(User $actor, Source $source): Source
    {
        return DB::transaction(function () use ($actor, $source) {
            $source->restore();
            $this->log->record('sources.restored', $source, ['title' => $source->title], $actor);

            return $source->refresh()->loadCount('references');
        });
    }

    /** يملأ أعمدة البحث المطبَّعة للحقول المُرسلة فقط؛ النص الأصلي لا يُمس. */
    private function withSearch(array $data): array
    {
        if (array_key_exists('title', $data)) {
            $data['title_search'] = $this->normalizer->normalize($data['title']);
        }
        if (array_key_exists('author', $data)) {
            $data['author_search'] = $this->normalizer->normalize($data['author']);
        }

        return $data;
    }
}
