<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\MergeRequestStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\MergeRequestResource;
use App\Models\Person;
use App\Models\PersonMergeRequest;
use App\Services\Merges\PersonMergeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

class PersonMergeController extends Controller
{
    public function __construct(private PersonMergeService $merges) {}

    public function index(Request $request)
    {
        $data = $request->validate([
            'status' => ['sometimes', 'nullable', Rule::in(array_column(MergeRequestStatus::cases(), 'value'))],
            'person_id' => ['sometimes', 'nullable', 'integer'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $query = PersonMergeRequest::query()->with(['primaryPerson', 'secondaryPerson', 'requester:id,name', 'decider:id,name'])->orderByDesc('id');
        if (! empty($data['status'])) {
            $query->where('status', $data['status']);
        }
        if (! empty($data['person_id'])) {
            $query->where(fn ($q) => $q->where('primary_person_id', $data['person_id'])->orWhere('secondary_person_id', $data['person_id']));
        }
        $page = $query->paginate((int) ($data['per_page'] ?? 25));
        $this->exposePeople($request, collect($page->items()));

        return MergeRequestResource::collection($page);
    }

    public function show(Request $request, int $mergeRequest): JsonResponse
    {
        $model = $this->find($mergeRequest);
        $this->exposePeople($request, collect([$model]));
        $resource = new MergeRequestResource($model);
        if ($model->status === MergeRequestStatus::Pending) {
            $resource->withPreview($this->merges->preview($model));
        }

        return $resource->response();
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'primary_person_id' => ['required', 'integer'],
            'secondary_person_id' => ['required', 'integer'],
            'reason' => ['required', 'string', 'min:5', 'max:5000'],
        ]);
        $model = $this->merges->request($request->user(), (int) $data['primary_person_id'], (int) $data['secondary_person_id'], $data['reason']);

        return $this->respond($request, $model, 201);
    }

    public function approve(Request $request, int $mergeRequest): JsonResponse
    {
        $request->validate(['notes' => ['sometimes', 'nullable', 'string', 'max:5000']]);
        $model = $this->merges->approve($request->user(), $this->find($mergeRequest), $request->input('notes'));

        return $this->respond($request, $model);
    }

    public function reject(Request $request, int $mergeRequest): JsonResponse
    {
        $data = $request->validate(['notes' => ['required', 'string', 'max:5000']]);

        return $this->respond($request, $this->merges->reject($request->user(), $this->find($mergeRequest), $data['notes']));
    }

    /** سحب الطلب: لصاحبه فقط، وما دام معلّقًا. */
    public function withdraw(Request $request, int $mergeRequest): JsonResponse
    {
        $model = $this->find($mergeRequest);
        abort_unless((int) $model->requested_by === (int) $request->user()->getKey(), 403, 'يسحب الطلبَ صاحبُه فقط.');

        return $this->respond($request, $this->merges->withdraw($request->user(), $model));
    }

    public function revert(Request $request, int $mergeRequest): JsonResponse
    {
        $data = $request->validate(['notes' => ['required', 'string', 'max:5000']]);

        return $this->respond($request, $this->merges->revert($request->user(), $this->find($mergeRequest), $data['notes']));
    }

    private function find(int $id): PersonMergeRequest
    {
        return PersonMergeRequest::query()->with(['primaryPerson', 'secondaryPerson', 'requester:id,name', 'decider:id,name'])->findOrFail($id);
    }

    private function respond(Request $request, PersonMergeRequest $model, int $status = 200): JsonResponse
    {
        $model = $this->find($model->getKey());
        $this->exposePeople($request, collect([$model]));

        return (new MergeRequestResource($model))->response()->setStatusCode($status);
    }

    /** أرقام الأعلام التي يراها المستخدم فقط؛ تُعرض أسماؤها وغيرها يُحجب. */
    private function exposePeople(Request $request, Collection $models): void
    {
        $ids = $models->flatMap(fn (PersonMergeRequest $m) => [$m->primary_person_id, $m->secondary_person_id])->unique()->values()->all();
        // العلَم المدموج نفسه يُعرض هنا لأنه طرف في الطلب، لذا نتجاوز مقيّد "الأساسي" ونكتفي بقيد الرؤية
        $visible = Person::withTrashed()->visibleTo($request->user())->whereIn('id', $ids)->pluck('id')->map(fn ($i) => (int) $i)->all();
        $request->attributes->set('visible_person_ids', $visible);
    }
}
