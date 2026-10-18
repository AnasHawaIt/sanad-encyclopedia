<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\PublicationStatus;
use App\Enums\VerificationStatus;
use App\Http\Controllers\Api\V1\Concerns\FindsRecords;
use App\Http\Controllers\Controller;
use App\Http\Requests\Asnads\AsnadRequest;
use App\Http\Resources\AsnadBriefResource;
use App\Http\Resources\AsnadResource;
use App\Models\Asnad;
use App\Models\User;
use App\Services\Asnad\AsnadService;
use App\Services\Asnad\ChainCounter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AsnadController extends Controller
{
    use FindsRecords;

    public function __construct(private AsnadService $asnads, private ChainCounter $counter) {}

    public function index(Request $request)
    {
        $request->validate([
            'q' => ['sometimes', 'nullable', 'string', 'max:200'],
            'status' => ['sometimes', 'nullable', 'in:trashed'],
            'verification_status' => ['sometimes', 'nullable', Rule::enum(VerificationStatus::class)],
            'publication_status' => ['sometimes', 'nullable', Rule::enum(PublicationStatus::class)],
            'sanad_type_id' => ['sometimes', 'nullable', 'integer'],
            'book_id' => ['sometimes', 'nullable', 'integer'],
            'ijaza_id' => ['sometimes', 'nullable', 'integer'],
            'person_id' => ['sometimes', 'nullable', 'integer'],
            'sort' => ['sometimes', 'nullable', 'in:title,recent'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $user = $request->user();
        $query = Asnad::query()->visibleTo($user)->with(['type', 'nodes']);

        if ($request->input('status') === 'trashed') {
            abort_unless($this->seesArchived($request, 'asnads.delete'), 403);
            $query->onlyTrashed();
        }
        if ($term = $request->input('q')) {
            $query->searchText($term);
        }
        foreach (['verification_status', 'publication_status', 'sanad_type_id'] as $filter) {
            if ($value = $request->input($filter)) {
                $query->where($filter, $value);
            }
        }
        if ($book = $request->input('book_id')) {
            $query->whereHas('books', fn ($q) => $q->whereKey($book)->visibleTo($user));
        }
        if ($ijaza = $request->input('ijaza_id')) {
            $query->whereHas('ijazas', fn ($q) => $q->whereKey($ijaza)->visibleTo($user));
        }
        // السندات التي فيها هذا العلَم (بأي موضع)
        if ($person = $request->input('person_id')) {
            $query->whereHas('nodes', fn ($q) => $q->where('person_id', $person));
        }

        $request->input('sort', 'recent') === 'title' ? $query->orderBy('title') : $query->orderByDesc('id');
        $query->orderBy('id');

        return AsnadBriefResource::collection($query->paginate($request->integer('per_page', 25)));
    }

    public function show(Request $request, int $asnad): AsnadResource
    {
        $model = $this->findVisible(Asnad::class, $asnad, $request, $this->seesArchived($request, 'asnads.delete'));

        return $this->present($request, $model);
    }

    public function store(AsnadRequest $request): JsonResponse
    {
        $asnad = $this->asnads->create($request->user(), $request->validated());

        return $this->present($request, $asnad)->response()->setStatusCode(201);
    }

    public function update(AsnadRequest $request, int $asnad): AsnadResource
    {
        $model = $this->findVisible(Asnad::class, $asnad, $request);

        return $this->present($request, $this->asnads->update($request->user(), $model, $request->validated()));
    }

    public function destroy(Request $request, int $asnad): JsonResponse
    {
        $this->asnads->archive($request->user(), $this->findVisible(Asnad::class, $asnad, $request));

        return response()->json(null, 204);
    }

    public function restore(Request $request, int $asnad): AsnadResource
    {
        $model = Asnad::onlyTrashed()->findOrFail($asnad);

        return $this->present($request, $this->asnads->restore($request->user(), $model));
    }

    /** السند بكل ما يظهر منه للمستخدم: عناصر بأعلام مرئية فقط، وكتب وإجازات مرئية فقط. */
    public static function load(Asnad $asnad, ?User $user): Asnad
    {
        $visible = fn ($q) => $q->visibleTo($user);

        return $asnad->load([
            'type',
            'nodes.person' => $visible,
            'nodes.role',
            'nodes.transmissionMode',
            'books' => $visible,
            'ijazas' => $visible,
        ]);
    }

    private function present(Request $request, Asnad $asnad): AsnadResource
    {
        $asnad = self::load($asnad, $request->user());

        return (new AsnadResource($asnad))->withAuthors($this->counter->authorCheck($asnad, $asnad->books));
    }
}
