<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\PublicationStatus;
use App\Enums\VerificationStatus;
use App\Http\Controllers\Api\V1\Concerns\FindsRecords;
use App\Http\Controllers\Controller;
use App\Http\Requests\Persons\PersonRequest;
use App\Http\Resources\PersonBriefResource;
use App\Http\Resources\PersonResource;
use App\Models\Person;
use App\Services\Persons\PersonService;
use App\Services\Persons\SimilarPersonFinder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PersonController extends Controller
{
    use FindsRecords;

    public function __construct(private PersonService $persons, private SimilarPersonFinder $similar) {}

    public function index(Request $request)
    {
        $request->validate([
            'q' => ['sometimes', 'nullable', 'string', 'max:200'],
            'status' => ['sometimes', 'nullable', 'in:trashed'],
            'verification_status' => ['sometimes', 'nullable', Rule::enum(VerificationStatus::class)],
            'publication_status' => ['sometimes', 'nullable', Rule::enum(PublicationStatus::class)],
            'death_from' => ['sometimes', 'nullable', 'integer', 'between:1,1700'],
            'death_to' => ['sometimes', 'nullable', 'integer', 'between:1,1700'],
            'sort' => ['sometimes', 'nullable', 'in:name,death,recent'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $user = $request->user();
        // المدموجون يُخفون من القوائم؛ صفحاتهم القديمة تبقى تحوّل إلى الأساسي
        $query = Person::query()->canonical()->visibleTo($user)->with('nameVariants');

        if ($request->input('status') === 'trashed') {
            abort_unless($this->seesArchived($request, 'persons.delete'), 403);
            $query->onlyTrashed();
        }
        if ($term = $request->input('q')) {
            $query->searchName($term);
        }
        // فلاتر الحالة لا تُوسّع الرؤية: visibleTo أعلاه يبقى هو الحاكم
        if ($v = $request->input('verification_status')) {
            $query->where('verification_status', $v);
        }
        if ($p = $request->input('publication_status')) {
            $query->where('publication_status', $p);
        }
        if ($from = $request->input('death_from')) {
            $query->where('death_year_h', '>=', $from);
        }
        if ($to = $request->input('death_to')) {
            $query->where('death_year_h', '<=', $to);
        }

        match ($request->input('sort', 'name')) {
            'death' => $query->orderBy('death_year_h')->orderBy('primary_name'),
            'recent' => $query->orderByDesc('id'),
            default => $query->orderBy('primary_name'),
        };
        $query->orderBy('id');

        return PersonBriefResource::collection($query->paginate($request->integer('per_page', 25)));
    }

    public function show(Request $request, int $person): PersonResource
    {
        $model = $this->findVisible(Person::class, $person, $request, $this->seesArchived($request, 'persons.delete'));

        return new PersonResource($model->load(['nameVariants', 'dateClaims']));
    }

    public function store(PersonRequest $request): JsonResponse
    {
        $person = $this->persons->create($request->user(), $request->validated());

        return $this->withSimilar($request, $person)->setStatusCode(201);
    }

    public function update(PersonRequest $request, int $person): JsonResponse
    {
        $model = $this->findVisible(Person::class, $person, $request);
        $updated = $this->persons->update($request->user(), $model, $request->validated());

        return $this->withSimilar($request, $updated);
    }

    public function destroy(Request $request, int $person): JsonResponse
    {
        $this->persons->archive($request->user(), $this->findVisible(Person::class, $person, $request));

        return response()->json(null, 204);
    }

    public function restore(Request $request, int $person): PersonResource
    {
        $model = Person::onlyTrashed()->findOrFail($person);

        return new PersonResource($this->persons->restore($request->user(), $model));
    }

    /** اقتراحات للمراجع فقط: لا تُحفظ ولا تدمج ولا تغيّر أي سجل. */
    public function similar(Request $request, int $person): JsonResponse
    {
        $model = $this->findVisible(Person::class, $person, $request);

        return response()->json(['data' => $this->similarPayload($request, $model)]);
    }

    private function withSimilar(Request $request, Person $person): JsonResponse
    {
        return (new PersonResource($person))
            ->additional(['similar' => $this->similarPayload($request, $person)])
            ->response();
    }

    /** @return list<array<string,mixed>> */
    private function similarPayload(Request $request, Person $person): array
    {
        return array_map(fn (array $row) => [
            'person' => (new PersonBriefResource($row['person']))->resolve($request),
            'score' => $row['score'],
            'reasons' => $row['reasons'],
        ], $this->similar->for($person, $request->user()));
    }
}
