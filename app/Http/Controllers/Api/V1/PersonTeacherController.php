<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\RelationType;
use App\Enums\VerificationStatus;
use App\Http\Controllers\Api\V1\Concerns\FindsRecords;
use App\Http\Controllers\Controller;
use App\Http\Requests\Persons\RelationRequest;
use App\Http\Resources\PersonBriefResource;
use App\Models\Person;
use App\Models\PersonRelation;
use App\Services\Persons\PersonService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** علاقات الشيخ والتلميذ لعلَم: الشيوخ والتلاميذ المرئيون فقط. */
class PersonTeacherController extends Controller
{
    use FindsRecords;

    public function __construct(private PersonService $persons) {}

    public function index(Request $request, int $person): JsonResponse
    {
        $model = $this->findVisible(Person::class, $person, $request);
        $user = $request->user();

        $rows = fn (string $own, string $other, string $relation) => PersonRelation::query()
            ->where($own, $model->getKey())
            ->whereHas($relation, fn ($q) => $q->canonical()->visibleTo($user))
            ->with([$relation => fn ($q) => $q->visibleTo($user)])
            ->orderBy('id')->get()
            ->map(fn (PersonRelation $r) => $this->present($request, $r, $relation))->values();

        return response()->json(['data' => [
            'teachers' => $rows('student_person_id', 'teacher_person_id', 'teacher'),
            'students' => $rows('teacher_person_id', 'student_person_id', 'student'),
        ]]);
    }

    public function store(RelationRequest $request, int $person): JsonResponse
    {
        $student = $this->findVisible(Person::class, $person, $request);
        $relation = $this->persons->addTeacher($request->user(), $student, $request->validated());
        $relation->load(['teacher' => fn ($q) => $q->visibleTo($request->user())]);

        return response()->json(['data' => $this->present($request, $relation, 'teacher')], 201);
    }

    public function destroy(Request $request, int $person, int $relation): JsonResponse
    {
        $model = $this->findVisible(Person::class, $person, $request);
        $this->persons->removeRelation($request->user(), $model, $relation);

        return response()->json(null, 204);
    }

    /** @return array<string,mixed> */
    private function present(Request $request, PersonRelation $relation, string $side): array
    {
        $internal = (bool) $request->user()?->can('records.view_unpublished');
        $verification = VerificationStatus::tryFrom((string) $relation->verification_status);
        $type = RelationType::tryFrom((string) $relation->relation_type);

        return [
            'id' => $relation->id,
            'relation_type' => ['value' => $relation->relation_type, 'label' => $type?->label()],
            'verification_status' => ['value' => $relation->verification_status, 'label' => $verification?->label()],
            'person' => $relation->{$side} ? (new PersonBriefResource($relation->{$side}))->resolve($request) : null,
            'notes' => $internal ? $relation->notes : null,
        ];
    }
}
