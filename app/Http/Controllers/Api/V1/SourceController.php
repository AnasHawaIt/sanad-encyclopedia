<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\SourceType;
use App\Http\Controllers\Api\V1\Concerns\FindsRecords;
use App\Http\Controllers\Controller;
use App\Http\Requests\Sources\SourceRequest;
use App\Http\Resources\SourceResource;
use App\Models\Source;
use App\Services\Sources\SourceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SourceController extends Controller
{
    use FindsRecords;

    public function __construct(private SourceService $sources) {}

    public function index(Request $request)
    {
        $request->validate([
            'q' => ['sometimes', 'nullable', 'string', 'max:200'],
            'source_type' => ['sometimes', 'nullable', Rule::enum(SourceType::class)],
            'status' => ['sometimes', 'nullable', 'in:trashed'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Source::query()->withCount('references')->orderBy('title')->orderBy('id');

        if ($request->input('status') === 'trashed') {
            abort_unless($this->seesArchived($request, 'sources.delete'), 403);
            $query->onlyTrashed();
        }
        if ($term = $request->input('q')) {
            $query->search($term);
        }
        if ($type = $request->input('source_type')) {
            $query->where('source_type', $type);
        }

        return SourceResource::collection($query->paginate($request->integer('per_page', 25)));
    }

    public function show(Request $request, int $source): SourceResource
    {
        $query = Source::query()->withCount('references');
        if ($this->seesArchived($request, 'sources.delete')) {
            $query->withTrashed();
        }

        return new SourceResource($query->findOrFail($source));
    }

    public function store(SourceRequest $request): JsonResponse
    {
        return (new SourceResource($this->sources->create($request->user(), $request->validated())))
            ->response()->setStatusCode(201);
    }

    public function update(SourceRequest $request, int $source): SourceResource
    {
        $model = Source::query()->findOrFail($source);

        return new SourceResource($this->sources->update($request->user(), $model, $request->validated()));
    }

    public function destroy(Request $request, int $source): JsonResponse
    {
        $this->sources->archive($request->user(), Source::query()->findOrFail($source));

        return response()->json(null, 204);
    }

    public function restore(Request $request, int $source): SourceResource
    {
        $model = Source::onlyTrashed()->findOrFail($source);

        return new SourceResource($this->sources->restore($request->user(), $model));
    }
}
