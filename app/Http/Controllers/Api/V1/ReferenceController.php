<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ReferenceStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Sources\ReferenceRequest;
use App\Http\Requests\Sources\ReferenceStatusRequest;
use App\Http\Resources\ReferenceResource;
use App\Models\Asnad;
use App\Models\Book;
use App\Models\Ijaza;
use App\Models\Person;
use App\Services\Sources\ReferenceService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * مراجع علَم أو كتاب أو سند أو إجازة. النوع يأتي من المسار (persons|books|asnads|ijazas)، والكيان يُجلب بقيد الرؤية:
 * سجل غير مرئي للمستخدم يعطي 404 فلا تُكشف مراجعه.
 */
class ReferenceController extends Controller
{
    public function __construct(private ReferenceService $references) {}

    public function index(Request $request, int $entity)
    {
        $model = $this->entity($request, $entity);

        return ReferenceResource::collection($this->references->listFor($model, $request->user()));
    }

    public function store(ReferenceRequest $request, int $entity): JsonResponse
    {
        $model = $this->entity($request, $entity);
        $reference = $this->references->add($request->user(), $model, $request->validated());

        return (new ReferenceResource($reference))->response()->setStatusCode(201);
    }

    public function update(ReferenceRequest $request, int $entity, int $reference): ReferenceResource
    {
        $model = $this->entity($request, $entity);
        $current = $model->sourceReferences()->findOrFail($reference);

        return new ReferenceResource($this->references->update($request->user(), $model, $current, $request->validated()));
    }

    /** تحقيق المرجع أو رفضه: من صلاحية المراجع العلمي (reviews.decide) وحده. */
    public function status(ReferenceStatusRequest $request, int $entity, int $reference): ReferenceResource
    {
        $model = $this->entity($request, $entity);
        $current = $model->sourceReferences()->findOrFail($reference);
        $status = ReferenceStatus::from($request->validated()['reference_status']);

        return new ReferenceResource($this->references->decide($request->user(), $model, $current, $status));
    }

    public function destroy(Request $request, int $entity, int $reference): JsonResponse
    {
        $model = $this->entity($request, $entity);
        $this->references->remove($request->user(), $model, $model->sourceReferences()->findOrFail($reference));

        return response()->json(null, 204);
    }

    /** النوع يأتي من افتراضيات المسار (defaults) لا من وسائط الدالة، فلا يتأثر بترتيبها. */
    private function entity(Request $request, int $id): Model
    {
        $class = match ($request->route('type')) {
            'person' => Person::class,
            'book' => Book::class,
            'asnad' => Asnad::class,
            'ijaza' => Ijaza::class,
            default => abort(404),
        };

        return $class::query()->visibleTo($request->user())->findOrFail($id);
    }
}
