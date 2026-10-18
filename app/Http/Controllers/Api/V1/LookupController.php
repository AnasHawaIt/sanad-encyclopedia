<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Calendar;
use App\Enums\Certainty;
use App\Enums\ClaimType;
use App\Enums\ContributorRole;
use App\Enums\EditionType;
use App\Enums\NameVariantType;
use App\Enums\PublicationStatus;
use App\Enums\ReferenceStatus;
use App\Enums\RelationType;
use App\Enums\SourceType;
use App\Enums\VerificationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Lookups\CategoryRequest;
use App\Models\SubjectCategory;
use App\Services\Asnad\ChainCounter;
use App\Services\Audit\ActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** قوائم مرجعية للنماذج: القيم والتسميات من الخادم، فلا تُكتب نصًّا في الواجهة. */
class LookupController extends Controller
{
    public function __construct(private ActivityLogger $log) {}

    public function options(): JsonResponse
    {
        return response()->json(['data' => [
            'verification_statuses' => VerificationStatus::options(),
            'publication_statuses' => PublicationStatus::options(),
            'source_types' => SourceType::options(),
            'edition_types' => EditionType::options(),
            'contributor_roles' => ContributorRole::options(),
            'relation_types' => RelationType::options(),
            'name_variant_types' => NameVariantType::options(),
            'claim_types' => ClaimType::options(),
            'calendars' => Calendar::options(),
            'certainties' => Certainty::options(),
            'reference_statuses' => ReferenceStatus::options(),
            // تعريف كل رقم يُعرض في الواجهات (الأسانيد): كل رقم يُعرض معه تعريفه
            'count_definitions' => app(ChainCounter::class)->definitions(),
        ]]);
    }

    public function categories(Request $request): JsonResponse
    {
        $query = SubjectCategory::query()->ordered();
        if (! $request->user()->can('lookups.manage')) {
            $query->active();
        }

        return response()->json(['data' => $query->get()->map(fn (SubjectCategory $c) => $this->present($c))->values()]);
    }

    public function storeCategory(CategoryRequest $request): JsonResponse
    {
        $this->assertUniqueName($request->validated()['name'], $request->validated()['parent_id'] ?? null, null);
        $category = SubjectCategory::create($request->validated());
        $this->log->record('lookups.category_created', $category, ['name' => $category->name], $request->user());

        return response()->json(['data' => $this->present($category->refresh())], 201);
    }

    public function updateCategory(CategoryRequest $request, int $category): JsonResponse
    {
        $model = SubjectCategory::query()->findOrFail($category);
        $data = $request->validated();

        if (array_key_exists('parent_id', $data)) {
            $this->assertNoCycle($model, $data['parent_id']);
        }
        $this->assertUniqueName(
            $data['name'] ?? $model->name,
            array_key_exists('parent_id', $data) ? $data['parent_id'] : $model->parent_id,
            $model->getKey(),
        );

        $model->fill($data)->save();
        $this->log->record('lookups.category_updated', $model, $model->getChanges() === [] ? [] : array_diff_key($model->getChanges(), ['updated_at' => 1]), $request->user());

        return response()->json(['data' => $this->present($model->refresh())]);
    }

    private function assertNoCycle(SubjectCategory $category, ?int $parentId): void
    {
        for ($cursor = $parentId; $cursor !== null; $cursor = SubjectCategory::query()->whereKey($cursor)->value('parent_id')) {
            if ($cursor === $category->getKey()) {
                throw ValidationException::withMessages(['parent_id' => __('records.category_cycle')]);
            }
        }
    }

    private function assertUniqueName(string $name, ?int $parentId, ?int $ignoreId): void
    {
        $exists = SubjectCategory::query()
            ->where('name', $name)
            ->when($parentId === null, fn ($q) => $q->whereNull('parent_id'), fn ($q) => $q->where('parent_id', $parentId))
            ->when($ignoreId !== null, fn ($q) => $q->whereKeyNot($ignoreId))
            ->exists();
        if ($exists) {
            throw ValidationException::withMessages(['name' => __('records.category_duplicate')]);
        }
    }

    /** @return array<string,mixed> */
    private function present(SubjectCategory $c): array
    {
        return [
            'id' => $c->id,
            'name' => $c->name,
            'parent_id' => $c->parent_id,
            'description' => $c->description,
            'is_active' => $c->is_active,
            'sort_order' => $c->sort_order,
        ];
    }
}
