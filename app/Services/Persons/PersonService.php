<?php

namespace App\Services\Persons;

use App\Enums\Calendar;
use App\Enums\ClaimType;
use App\Models\AsnadNode;
use App\Models\Book;
use App\Models\BookContributor;
use App\Models\BookEdition;
use App\Models\Ijaza;
use App\Models\Person;
use App\Models\PersonRelation;
use App\Models\User;
use App\Services\Audit\ActivityLogger;
use App\Services\Concerns\SyncsChildren;
use App\Services\Search\ArabicNormalizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PersonService
{
    use SyncsChildren;

    private const FIELDS = [
        'primary_name', 'full_name', 'kunyah', 'nisbah',
        'birth_year_h', 'death_year_h', 'country_or_region', 'biography', 'notes',
    ];

    public function __construct(
        private ActivityLogger $log,
        private ArabicNormalizer $normalizer,
        private PersonAccess $access,
    ) {}

    public function create(User $actor, array $data): Person
    {
        return DB::transaction(function () use ($actor, $data) {
            $attributes = array_intersect_key($data, array_flip(self::FIELDS));
            $attributes = $this->withDerivedYears($attributes, $data['date_claims'] ?? null);

            $person = new Person($attributes);
            $person->name_search = $this->searchText($person);
            $person->created_by = $actor->getKey();
            $person->updated_by = $actor->getKey();
            $person->save();

            $this->syncVariants($person, $data['name_variants'] ?? []);
            $this->syncClaims($person, $data['date_claims'] ?? []);

            $this->log->record('persons.created', $person, ['primary_name' => $person->primary_name], $actor);

            return $this->loaded($person->refresh());
        });
    }

    public function update(User $actor, Person $person, array $data): Person
    {
        return DB::transaction(function () use ($actor, $person, $data) {
            $attributes = array_intersect_key($data, array_flip(self::FIELDS));
            $attributes = $this->withDerivedYears($attributes, $data['date_claims'] ?? null);

            $person->fill($attributes);
            $person->name_search = $this->searchText($person);
            $person->updated_by = $actor->getKey();
            $person->save();

            $changes = $this->diff($person, ['name_search']);
            if (array_key_exists('name_variants', $data) && $this->syncVariants($person, $data['name_variants'])) {
                $changes['name_variants'] = 'updated';
            }
            if (array_key_exists('date_claims', $data) && $this->syncClaims($person, $data['date_claims'])) {
                $changes['date_claims'] = 'updated';
            }

            if ($changes !== []) {
                $this->log->record('persons.updated', $person, $changes, $actor);
            }

            return $this->loaded($person->refresh());
        });
    }

    /** الأرشفة لا تحذف شيئًا، لكنها تُرفض لعلَم ما زالت كتب أو إجازات أو أسانيد تشير إليه. */
    public function archive(User $actor, Person $person): void
    {
        $usage = $this->usage($person);
        if ($usage !== []) {
            $details = collect($usage)
                ->map(fn (int $count, string $key) => __("records.counts.{$key}").": {$count}")
                ->implode('، ');

            throw ValidationException::withMessages(['person' => __('records.person_in_use', ['details' => $details])]);
        }

        DB::transaction(function () use ($actor, $person) {
            $person->delete();
            $this->log->record('persons.archived', $person, ['primary_name' => $person->primary_name], $actor);
        });
    }

    public function restore(User $actor, Person $person): Person
    {
        if ($person->merged_into_person_id !== null) {
            throw ValidationException::withMessages(['person' => __('records.person_merged')]);
        }

        return DB::transaction(function () use ($actor, $person) {
            $person->restore();
            $this->log->record('persons.restored', $person, ['primary_name' => $person->primary_name], $actor);

            return $this->loaded($person->refresh());
        });
    }

    public function addTeacher(User $actor, Person $student, array $data): PersonRelation
    {
        $teacherId = (int) $data['teacher_person_id'];
        $type = $data['relation_type'] ?? 'unspecified';

        if ($teacherId === $student->getKey()) {
            throw ValidationException::withMessages(['teacher_person_id' => __('records.relation_self')]);
        }
        $this->access->assertUsable($actor, [$teacherId], 'teacher_person_id');

        $duplicate = PersonRelation::query()
            ->where('teacher_person_id', $teacherId)
            ->where('student_person_id', $student->getKey())
            ->where('relation_type', $type)
            ->exists();
        if ($duplicate) {
            throw ValidationException::withMessages(['relation_type' => __('records.relation_duplicate')]);
        }

        return DB::transaction(function () use ($actor, $student, $teacherId, $type, $data) {
            $relation = PersonRelation::create([
                'teacher_person_id' => $teacherId,
                'student_person_id' => $student->getKey(),
                'relation_type' => $type,
                'notes' => $data['notes'] ?? null,
                'created_by' => $actor->getKey(),
                'updated_by' => $actor->getKey(),
            ]);
            $this->log->record('persons.teacher_added', $student, [
                'teacher_person_id' => $teacherId, 'relation_type' => $type,
            ], $actor);

            return $relation->refresh();
        });
    }

    /** يحذف علاقة (وحدها مع مراجعها) لا يملكها إلا أحد طرفيها. */
    public function removeRelation(User $actor, Person $person, int $relationId): void
    {
        $relation = PersonRelation::query()
            ->whereKey($relationId)
            ->where(fn ($q) => $q->where('teacher_person_id', $person->getKey())->orWhere('student_person_id', $person->getKey()))
            ->firstOrFail();

        DB::transaction(function () use ($actor, $person, $relation) {
            $relation->sourceReferences()->delete();
            $relation->delete();
            $this->log->record('persons.relation_removed', $person, [
                'teacher_person_id' => $relation->teacher_person_id,
                'student_person_id' => $relation->student_person_id,
                'relation_type' => $relation->relation_type,
            ], $actor);
        });
    }

    /** @return array<string,int> ما يمنع الأرشفة، بعدد كل نوع (الأنواع الصفرية محذوفة) */
    public function usage(Person $person): array
    {
        $id = $person->getKey();
        $counts = [
            'authored_books' => Book::withTrashed()->where('author_person_id', $id)->count(),
            'contributions' => BookContributor::query()->where('person_id', $id)->count(),
            'edited_editions' => BookEdition::withTrashed()->where('editor_person_id', $id)->count(),
            'ijazas' => Ijaza::withTrashed()->where(fn ($q) => $q->where('grantor_person_id', $id)->orWhere('recipient_person_id', $id))->count(),
            'asnad_nodes' => AsnadNode::query()->where('person_id', $id)->count(),
        ];

        return array_filter($counts);
    }

    private function searchText(Person $person): string
    {
        return $this->normalizer->normalize(implode(' ', array_filter([
            $person->primary_name, $person->full_name, $person->kunyah, $person->nisbah,
        ])));
    }

    /**
     * سنة الولادة والوفاة الهجرية تُؤخذ من القول المعتمد الهجري فقط. لا تحويل من الميلادي أبدًا،
     * وإن أُرسلت سنة صريحة تخالف القول المعتمد رُفض الطلب بدل اختيار أحدهما بصمت.
     *
     * @param  array<string,mixed>  $attributes
     * @param  list<array<string,mixed>>|null  $claims
     */
    private function withDerivedYears(array $attributes, ?array $claims): array
    {
        if ($claims === null) {
            return $attributes;
        }

        foreach ([ClaimType::Birth->value => 'birth_year_h', ClaimType::Death->value => 'death_year_h'] as $type => $field) {
            $preferred = collect($claims)->first(fn ($c) => ($c['claim_type'] ?? null) === $type
                && ! empty($c['is_preferred'])
                && ($c['calendar'] ?? Calendar::Hijri->value) === Calendar::Hijri->value);

            if ($preferred === null) {
                continue;
            }
            $year = (int) $preferred['year'];
            if (isset($attributes[$field]) && (int) $attributes[$field] !== $year) {
                throw ValidationException::withMessages([$field => __('records.year_mismatch')]);
            }
            $attributes[$field] = $year;
        }

        return $attributes;
    }

    private function syncVariants(Person $person, array $items): bool
    {
        return $this->syncChildren($person->nameVariants(), $items, fn (array $i) => [
            'name' => $i['name'],
            'name_search' => $this->normalizer->normalize($i['name']),
            'variant_type' => $i['variant_type'] ?? 'name',
            'notes' => $i['notes'] ?? null,
        ]);
    }

    private function syncClaims(Person $person, array $items): bool
    {
        return $this->syncChildren($person->dateClaims(), $items, fn (array $i) => [
            'claim_type' => $i['claim_type'],
            'year' => $i['year'],
            'calendar' => $i['calendar'] ?? Calendar::Hijri->value,
            'certainty' => $i['certainty'] ?? 'certain',
            'is_preferred' => (bool) ($i['is_preferred'] ?? false),
            'notes' => $i['notes'] ?? null,
        ]);
    }

    private function loaded(Person $person): Person
    {
        return $person->load(['nameVariants', 'dateClaims']);
    }
}
