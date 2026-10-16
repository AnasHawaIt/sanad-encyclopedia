<?php

namespace Tests\Feature\Database;

use App\Enums\MergeRequestStatus;
use App\Enums\VerificationStatus;
use App\Models\Person;
use App\Models\PersonDateClaim;
use App\Models\PersonMergeRequest;
use App\Models\PersonMergeSuggestion;
use App\Models\PersonRelation;
use App\Models\User;
use Tests\DatabaseTestCase;

class PersonIdentityTest extends DatabaseTestCase
{
    public function test_two_persons_with_the_same_name_remain_separate_records(): void
    {
        $a = Person::factory()->create(['primary_name' => 'اسم مكرر تجريبي', 'name_search' => 'اسم مكرر تجريبي']);
        $b = Person::factory()->create(['primary_name' => 'اسم مكرر تجريبي', 'name_search' => 'اسم مكرر تجريبي']);

        $this->assertNotSame($a->id, $b->id);
        $this->assertSame(2, Person::where('primary_name', 'اسم مكرر تجريبي')->count());
    }

    public function test_new_person_defaults_to_draft_and_unpublished(): void
    {
        $person = Person::create(['primary_name' => 'شخص بلا حالة محددة']);

        $this->assertSame(VerificationStatus::Draft, $person->verification_status);
        $this->assertSame('unpublished', $person->publication_status->value);
    }

    public function test_status_scopes_filter_verified_and_published(): void
    {
        Person::factory()->create();
        Person::factory()->verified()->create();
        Person::factory()->published()->create();

        $this->assertSame(2, Person::verified()->count());
        $this->assertSame(1, Person::published()->count());
    }

    public function test_a_similarity_suggestion_never_merges_anything(): void
    {
        $a = Person::factory()->create();
        $b = Person::factory()->create();

        $suggestion = PersonMergeSuggestion::create([
            'person_a_id' => $a->id,
            'person_b_id' => $b->id,
            'score' => 87.5,
            'reasons' => ['same_primary_name'],
        ]);

        $this->assertSame(['same_primary_name'], $suggestion->fresh()->reasons);
        $this->assertNull($a->fresh()->merged_into_person_id);
        $this->assertNull($b->fresh()->merged_into_person_id);
        $this->assertSame(2, Person::canonical()->count());
    }

    public function test_suggestion_pair_is_canonical_and_unique(): void
    {
        $a = Person::factory()->create();
        $b = Person::factory()->create();

        PersonMergeSuggestion::create(['person_a_id' => $a->id, 'person_b_id' => $b->id]);

        // الترتيب المعاكس مرفوض (a يجب أن يكون الأصغر)
        $this->assertRejectedByDatabase(
            fn () => PersonMergeSuggestion::create(['person_a_id' => $b->id, 'person_b_id' => $a->id])
        );
        // وتكرار الزوج نفسه مرفوض
        $this->assertRejectedByDatabase(
            fn () => PersonMergeSuggestion::create(['person_a_id' => $a->id, 'person_b_id' => $b->id])
        );
        // وكذلك اقتراح شخص مع نفسه
        $this->assertRejectedByDatabase(
            fn () => PersonMergeSuggestion::create(['person_a_id' => $a->id, 'person_b_id' => $a->id])
        );
    }

    public function test_merge_request_is_pending_by_default_and_cannot_target_the_same_person(): void
    {
        $user = User::factory()->create();
        $a = Person::factory()->create();
        $b = Person::factory()->create();

        $request = PersonMergeRequest::create([
            'primary_person_id' => $a->id,
            'secondary_person_id' => $b->id,
            'reason' => 'اختبار',
            'requested_by' => $user->id,
        ]);

        $this->assertSame(MergeRequestStatus::Pending, $request->status);

        $this->assertRejectedByDatabase(fn () => PersonMergeRequest::create([
            'primary_person_id' => $a->id,
            'secondary_person_id' => $a->id,
            'reason' => 'اختبار',
            'requested_by' => $user->id,
        ]));
    }

    public function test_repoint_log_keeps_old_values_for_a_safe_revert(): void
    {
        $user = User::factory()->create();
        $a = Person::factory()->create();
        $b = Person::factory()->create();
        $request = PersonMergeRequest::create([
            'primary_person_id' => $a->id,
            'secondary_person_id' => $b->id,
            'reason' => 'اختبار',
            'requested_by' => $user->id,
        ]);

        $request->repoints()->create([
            'table_name' => 'asnad_nodes',
            'row_id' => 10,
            'column_name' => 'person_id',
            'old_person_id' => $b->id,
        ]);

        $this->assertCount(1, $request->repoints);
        $this->assertSame($b->id, $request->repoints->first()->old_person_id);
    }

    public function test_a_merged_person_stays_in_the_database_and_is_hidden_only_by_the_canonical_scope(): void
    {
        $primary = Person::factory()->create();
        $secondary = Person::factory()->create();

        $secondary->update(['merged_into_person_id' => $primary->id]);

        $this->assertSame(2, Person::count());
        $this->assertSame([$primary->id], Person::canonical()->pluck('id')->all());
        $this->assertTrue($secondary->fresh()->mergedInto->is($primary));
        $this->assertTrue($primary->mergedFrom->first()->is($secondary));
    }

    public function test_teacher_and_student_links_are_directional_and_not_self_referencing(): void
    {
        $teacher = Person::factory()->create();
        $student = Person::factory()->create();

        PersonRelation::create(['teacher_person_id' => $teacher->id, 'student_person_id' => $student->id, 'relation_type' => 'reading']);
        // صورتان مختلفتان للأخذ بين الشخصين نفسيهما مسموحتان
        PersonRelation::create(['teacher_person_id' => $teacher->id, 'student_person_id' => $student->id, 'relation_type' => 'ijaza']);

        $this->assertSame([$teacher->id], $student->teachers()->pluck('persons.id')->unique()->all());
        $this->assertSame([$student->id], $teacher->students()->pluck('persons.id')->unique()->all());
        $this->assertSame(0, $teacher->teachers()->count());

        $this->assertRejectedByDatabase(
            fn () => PersonRelation::create(['teacher_person_id' => $teacher->id, 'student_person_id' => $teacher->id])
        );
    }

    public function test_conflicting_date_claims_and_name_variants_are_all_kept(): void
    {
        $person = Person::factory()->create();

        $person->dateClaims()->createMany([
            ['claim_type' => 'death', 'year' => 300, 'calendar' => 'hijri', 'certainty' => 'certain', 'is_preferred' => true],
            ['claim_type' => 'death', 'year' => 305, 'calendar' => 'hijri', 'certainty' => 'disputed'],
        ]);
        $person->nameVariants()->createMany([
            ['name' => 'صيغة أولى تجريبية', 'variant_type' => 'name'],
            ['name' => 'صيغة ثانية تجريبية', 'variant_type' => 'kunyah'],
        ]);

        $this->assertSame(2, $person->dateClaims()->count());
        $this->assertSame(1, PersonDateClaim::where('person_id', $person->id)->where('is_preferred', true)->count());
        $this->assertSame(2, $person->nameVariants()->count());
    }
}
