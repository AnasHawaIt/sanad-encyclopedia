<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\PublicationStatus;
use App\Enums\VerificationStatus;
use App\Http\Controllers\Api\V1\Concerns\FindsRecords;
use App\Http\Controllers\Controller;
use App\Http\Requests\Ijazas\IjazaRequest;
use App\Http\Resources\IjazaBriefResource;
use App\Http\Resources\IjazaResource;
use App\Models\Ijaza;
use App\Models\User;
use App\Services\Ijazas\IjazaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class IjazaController extends Controller
{
    use FindsRecords;

    public function __construct(private IjazaService $ijazas) {}

    public function index(Request $request)
    {
        $request->validate([
            'q' => ['sometimes', 'nullable', 'string', 'max:200'],
            'status' => ['sometimes', 'nullable', 'in:trashed'],
            'verification_status' => ['sometimes', 'nullable', Rule::enum(VerificationStatus::class)],
            'publication_status' => ['sometimes', 'nullable', Rule::enum(PublicationStatus::class)],
            'ijaza_type_id' => ['sometimes', 'nullable', 'integer'],
            'grantor_person_id' => ['sometimes', 'nullable', 'integer'],
            'recipient_person_id' => ['sometimes', 'nullable', 'integer'],
            'year_from' => ['sometimes', 'nullable', 'integer', 'between:1,1700'],
            'year_to' => ['sometimes', 'nullable', 'integer', 'between:1,1700'],
            'book_id' => ['sometimes', 'nullable', 'integer'],
            'asnad_id' => ['sometimes', 'nullable', 'integer'],
            'sort' => ['sometimes', 'nullable', 'in:year,recent'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $user = $request->user();
        $query = Ijaza::query()->visibleTo($user)->with($this->people($user, ['type']));

        if ($request->input('status') === 'trashed') {
            abort_unless($this->seesArchived($request, 'ijazas.delete'), 403);
            $query->onlyTrashed();
        }
        if ($term = $request->input('q')) {
            $query->searchText($term);
        }
        foreach (['verification_status', 'publication_status', 'ijaza_type_id', 'grantor_person_id', 'recipient_person_id'] as $filter) {
            if ($value = $request->input($filter)) {
                $query->where($filter, $value);
            }
        }
        if ($from = $request->input('year_from')) {
            $query->where('ijaza_year_h', '>=', $from);
        }
        if ($to = $request->input('year_to')) {
            $query->where('ijaza_year_h', '<=', $to);
        }
        if ($book = $request->input('book_id')) {
            $query->whereHas('books', fn ($q) => $q->whereKey($book)->visibleTo($user));
        }
        if ($asnad = $request->input('asnad_id')) {
            $query->whereHas('asnads', fn ($q) => $q->whereKey($asnad)->visibleTo($user));
        }

        $request->input('sort', 'recent') === 'year'
            ? $query->orderBy('ijaza_year_h')->orderBy('id')
            : $query->orderByDesc('id');

        return IjazaBriefResource::collection($query->paginate($request->integer('per_page', 25)));
    }

    public function show(Request $request, int $ijaza): IjazaResource
    {
        $model = $this->findVisible(Ijaza::class, $ijaza, $request, $this->seesArchived($request, 'ijazas.delete'));

        return $this->present($request, $model);
    }

    public function store(IjazaRequest $request): JsonResponse
    {
        $ijaza = $this->ijazas->create($request->user(), $request->validated());

        return $this->present($request, $ijaza)->response()->setStatusCode(201);
    }

    public function update(IjazaRequest $request, int $ijaza): IjazaResource
    {
        $model = $this->findVisible(Ijaza::class, $ijaza, $request);

        return $this->present($request, $this->ijazas->update($request->user(), $model, $request->validated()));
    }

    public function destroy(Request $request, int $ijaza): JsonResponse
    {
        $this->ijazas->archive($request->user(), $this->findVisible(Ijaza::class, $ijaza, $request));

        return response()->json(null, 204);
    }

    public function restore(Request $request, int $ijaza): IjazaResource
    {
        $model = Ijaza::onlyTrashed()->findOrFail($ijaza);

        return $this->present($request, $this->ijazas->restore($request->user(), $model));
    }

    /**
     * المجيز والمجاز له والكتب والأسانيد المرتبطة تُحمَّل بقيد الرؤية: علَم أو كتاب أو سند غير منشور لا يظهر لمن لا يراه.
     *
     * @param  list<string>  $extra
     * @return array<int|string,mixed>
     */
    private function people(?User $user, array $extra = []): array
    {
        $visible = fn ($q) => $q->visibleTo($user);

        return array_merge($extra, ['grantor' => $visible, 'recipient' => $visible]);
    }

    private function present(Request $request, Ijaza $ijaza): IjazaResource
    {
        $user = $request->user();
        $visible = fn ($q) => $q->visibleTo($user);
        $ijaza->load($this->people($user, ['type']) + ['books' => $visible, 'asnads' => $visible]);

        return (new IjazaResource($ijaza))->withWarnings($this->ijazas->warnings($ijaza));
    }
}
