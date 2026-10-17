<?php

namespace Tests\Feature\Records;

use App\Models\ActivityLog;
use App\Models\Book;
use App\Models\Person;
use App\Models\PersonMergeSuggestion;
use App\Models\PersonRelation;
use Tests\Concerns\CreatesRecordFixtures;
use Tests\DatabaseTestCase;

class PersonApiTest extends DatabaseTestCase
{
    use CreatesRecordFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpRecords();
    }

    public function test_researcher_creates_person_and_it_is_a_draft_with_normalized_search_text(): void
    {
        $res = $this->as($this->researcher)->postJson('/api/v1/persons', [
            'primary_name' => 'أَحْمَدُ بنُ حَنْبَل',
            'kunyah' => 'أبو عبد الله',
            'nisbah' => 'الشَّيبانيّ',
        ]);

        $res->assertCreated()
            ->assertJsonPath('data.verification_status.value', 'draft')
            ->assertJsonPath('data.publication_status.value', 'unpublished')
            ->assertJsonPath('data.primary_name', 'أَحْمَدُ بنُ حَنْبَل');

        $person = Person::firstOrFail();
        $this->assertSame('أَحْمَدُ بنُ حَنْبَل', $person->primary_name, 'النص الأصلي لا يُمس');
        $this->assertSame('احمد بن حنبل ابو عبد الله الشيباني', $person->name_search);
        $this->assertSame($this->researcher->id, $person->created_by);
        $this->assertDatabaseHas('activity_logs', ['event' => 'persons.created', 'user_id' => $this->researcher->id]);
    }

    public function test_status_fields_cannot_be_set_through_create_or_update(): void
    {
        $this->as($this->researcher)->postJson('/api/v1/persons', [
            'primary_name' => 'علم', 'verification_status' => 'verified', 'publication_status' => 'published',
            'merged_into_person_id' => 1, 'created_by' => 999,
        ])->assertCreated();

        $person = Person::firstOrFail();
        $this->assertSame('draft', $person->verification_status->value);
        $this->assertSame('unpublished', $person->publication_status->value);
        $this->assertNull($person->merged_into_person_id);
        $this->assertSame($this->researcher->id, $person->created_by);

        $this->as($this->researcher)->putJson("/api/v1/persons/{$person->id}", ['verification_status' => 'verified'])->assertOk();
        $this->assertSame('draft', $person->fresh()->verification_status->value);
    }

    public function test_validation_rejects_bad_input_in_arabic(): void
    {
        $this->as($this->researcher)->postJson('/api/v1/persons', [])
            ->assertStatus(422)->assertJsonValidationErrors(['primary_name']);

        $res = $this->as($this->researcher)->postJson('/api/v1/persons', [
            'primary_name' => 'س', 'birth_year_h' => 300, 'death_year_h' => 250,
        ])->assertStatus(422)->assertJsonValidationErrors(['death_year_h']);
        $this->assertStringContainsString('سنة الوفاة', $res->json('errors.death_year_h.0'));

        $this->as($this->researcher)->postJson('/api/v1/persons', ['primary_name' => 'س', 'death_year_h' => 5000])
            ->assertStatus(422)->assertJsonValidationErrors(['death_year_h']);
    }

    public function test_same_name_creates_a_separate_person_never_merges(): void
    {
        $this->as($this->researcher)->postJson('/api/v1/persons', ['primary_name' => 'محمد بن أحمد'])->assertCreated();
        $second = $this->as($this->researcher)->postJson('/api/v1/persons', ['primary_name' => 'محمد بن أحمد'])->assertCreated();

        $this->assertSame(2, Person::count());
        $this->assertSame(0, Person::whereNotNull('merged_into_person_id')->count());
        $this->assertNotEmpty($second->json('similar'), 'يُنبَّه الباحث إلى المتشابه');
    }

    public function test_search_ignores_diacritics_hamza_forms_and_word_order_and_finds_variants(): void
    {
        $a = Person::factory()->create(['primary_name' => 'إِسْمَاعِيلُ بنُ إِبْرَاهِيمَ', 'name_search' => 'اسماعيل بن ابراهيم']);
        $b = Person::factory()->create(['primary_name' => 'عبد الله', 'name_search' => 'عبد الله']);
        $b->nameVariants()->create(['name' => 'ابن المبارك', 'name_search' => 'ابن المبارك']);
        $c = Person::factory()->create(['primary_name' => 'زيد', 'name_search' => 'زيد']);

        $r = fn (string $q) => $this->ids($this->as($this->researcher)->getJson('/api/v1/persons?q='.urlencode($q))->assertOk());

        $this->assertSame([$a->id], $r('اسماعيل'));
        $this->assertSame([$a->id], $r('ابراهيم اسماعيل'));
        $this->assertSame([$a->id], $r('إسماعيل'));
        $this->assertSame([$b->id], $r('المبارك'), 'يجد الاسم عبر الصيغة البديلة');
        $this->assertSame([], $r('غير موجود'));
        $this->assertContains($c->id, $r(''), 'نص فارغ يعني بلا تصفية');
    }

    public function test_search_treats_like_wildcards_as_literal_text(): void
    {
        Person::factory()->create(['name_search' => 'اسم عادي']);

        $this->assertSame([], $this->ids($this->as($this->researcher)->getJson('/api/v1/persons?q='.urlencode('%'))->assertOk()));
    }

    public function test_reader_sees_only_verified_and_published_people(): void
    {
        $published = $this->publishedPerson();
        $draft = Person::factory()->create();
        $verifiedOnly = Person::factory()->verified()->create();

        $reader = $this->ids($this->as($this->reader)->getJson('/api/v1/persons')->assertOk());
        $this->assertSame([$published->id], $reader);

        $team = $this->ids($this->as($this->researcher)->getJson('/api/v1/persons')->assertOk());
        $this->assertEqualsCanonicalizing([$published->id, $draft->id, $verifiedOnly->id], $team);
    }

    public function test_hidden_person_is_404_for_reader_on_every_endpoint_not_403(): void
    {
        $draft = Person::factory()->create();

        $this->as($this->reader)->getJson("/api/v1/persons/{$draft->id}")->assertNotFound();
        $this->as($this->reader)->getJson("/api/v1/persons/{$draft->id}/similar")->assertNotFound();
        $this->as($this->reader)->getJson("/api/v1/persons/{$draft->id}/relations")->assertNotFound();
        $this->as($this->reader)->getJson("/api/v1/persons/{$draft->id}/references")->assertNotFound();
        $this->as($this->researcher)->getJson("/api/v1/persons/{$draft->id}")->assertOk();
    }

    public function test_internal_fields_are_hidden_from_reader(): void
    {
        $person = $this->publishedPerson(['notes' => 'ملاحظة داخلية', 'created_by' => $this->researcher->id]);

        $reader = $this->as($this->reader)->getJson("/api/v1/persons/{$person->id}")->assertOk();
        $reader->assertJsonMissingPath('data.notes')->assertJsonMissingPath('data.created_by');
        $this->assertStringNotContainsString('ملاحظة داخلية', $reader->getContent());

        $this->as($this->researcher)->getJson("/api/v1/persons/{$person->id}")->assertJsonPath('data.notes', 'ملاحظة داخلية');
    }

    public function test_reader_cannot_write(): void
    {
        $person = $this->publishedPerson();

        $this->as($this->reader)->postJson('/api/v1/persons', ['primary_name' => 'س'])->assertForbidden();
        $this->as($this->reader)->putJson("/api/v1/persons/{$person->id}", ['primary_name' => 'س'])->assertForbidden();
        $this->as($this->reader)->deleteJson("/api/v1/persons/{$person->id}")->assertForbidden();
        $this->as($this->researcher)->deleteJson("/api/v1/persons/{$person->id}")->assertForbidden();
    }

    public function test_update_changes_search_text_and_audits_only_real_changes(): void
    {
        $person = Person::factory()->create(['primary_name' => 'قديم', 'full_name' => null, 'name_search' => 'قديم']);

        $this->as($this->researcher)->putJson("/api/v1/persons/{$person->id}", ['primary_name' => 'جَدِيد'])->assertOk();
        $this->assertSame('جديد', $person->fresh()->name_search);
        $log = ActivityLog::where('event', 'persons.updated')->latest('id')->firstOrFail();
        $this->assertSame('قديم', $log->changes['primary_name']['from']);

        ActivityLog::query()->delete();
        $this->as($this->researcher)->putJson("/api/v1/persons/{$person->id}", ['primary_name' => 'جَدِيد'])->assertOk();
        $this->assertSame(0, ActivityLog::where('event', 'persons.updated')->count(), 'لا تدقيق لتعديل لم يغيّر شيئًا');
    }

    public function test_date_claims_set_preferred_hijri_years_and_gregorian_is_never_converted(): void
    {
        $res = $this->as($this->researcher)->postJson('/api/v1/persons', [
            'primary_name' => 'عَلَم',
            'date_claims' => [
                ['claim_type' => 'death', 'year' => 256, 'calendar' => 'hijri', 'is_preferred' => true, 'certainty' => 'certain'],
                ['claim_type' => 'death', 'year' => 870, 'calendar' => 'gregorian'],
                ['claim_type' => 'birth', 'year' => 194, 'calendar' => 'hijri', 'is_preferred' => true, 'certainty' => 'approximate'],
            ],
        ])->assertCreated();

        $person = Person::firstOrFail();
        $this->assertSame(256, $person->death_year_h);
        $this->assertSame(194, $person->birth_year_h);
        $this->assertCount(3, $res->json('data.date_claims'));

        $this->as($this->researcher)->postJson('/api/v1/persons', [
            'primary_name' => 'ميلادي فقط',
            'date_claims' => [['claim_type' => 'death', 'year' => 870, 'calendar' => 'gregorian', 'is_preferred' => true]],
        ])->assertCreated();
        $this->assertNull(Person::where('primary_name', 'ميلادي فقط')->firstOrFail()->death_year_h, 'لا تحويل آلي صامت');
    }

    public function test_conflicting_explicit_year_and_preferred_claim_is_rejected_not_silently_chosen(): void
    {
        $this->as($this->researcher)->postJson('/api/v1/persons', [
            'primary_name' => 'عَلَم', 'death_year_h' => 300,
            'date_claims' => [['claim_type' => 'death', 'year' => 256, 'is_preferred' => true]],
        ])->assertStatus(422)->assertJsonValidationErrors(['death_year_h']);
        $this->assertSame(0, Person::count());
    }

    public function test_only_one_preferred_claim_per_type(): void
    {
        $this->as($this->researcher)->postJson('/api/v1/persons', [
            'primary_name' => 'عَلَم',
            'date_claims' => [
                ['claim_type' => 'death', 'year' => 256, 'is_preferred' => true],
                ['claim_type' => 'death', 'year' => 257, 'is_preferred' => true],
            ],
        ])->assertStatus(422)->assertJsonValidationErrors(['date_claims']);
    }

    public function test_children_sync_updates_creates_and_removes_and_rejects_foreign_ids(): void
    {
        $person = Person::factory()->create();
        $other = Person::factory()->create();
        $foreign = $other->nameVariants()->create(['name' => 'غريب', 'name_search' => 'غريب']);

        $res = $this->as($this->researcher)->putJson("/api/v1/persons/{$person->id}", [
            'name_variants' => [['name' => 'لقب أول', 'variant_type' => 'laqab'], ['name' => 'كنية', 'variant_type' => 'kunyah']],
        ])->assertOk();
        $first = $res->json('data.name_variants.0.id');

        $this->as($this->researcher)->putJson("/api/v1/persons/{$person->id}", [
            'name_variants' => [['id' => $first, 'name' => 'لَقَب مُعدَّل']],
        ])->assertOk()->assertJsonCount(1, 'data.name_variants');
        $this->assertSame('لقب معدل', $person->nameVariants()->firstOrFail()->name_search);

        $this->as($this->researcher)->putJson("/api/v1/persons/{$person->id}", [
            'name_variants' => [['id' => $foreign->id, 'name' => 'اختطاف']],
        ])->assertStatus(422);
        $this->assertSame('غريب', $foreign->fresh()->name);

        $this->as($this->researcher)->putJson("/api/v1/persons/{$person->id}", ['name_variants' => []])->assertOk();
        $this->assertSame(0, $person->nameVariants()->count());
    }

    public function test_omitting_children_leaves_them_untouched(): void
    {
        $person = Person::factory()->create();
        $person->nameVariants()->create(['name' => 'باق', 'name_search' => 'باق']);

        $this->as($this->researcher)->putJson("/api/v1/persons/{$person->id}", ['notes' => 'x'])->assertOk();
        $this->assertSame(1, $person->nameVariants()->count());
    }

    public function test_person_in_use_cannot_be_archived_and_message_says_why(): void
    {
        $admin = $this->admin();
        $author = Person::factory()->create();
        Book::factory()->create(['author_person_id' => $author->id]);

        $res = $this->as($admin)->deleteJson("/api/v1/persons/{$author->id}")->assertStatus(422);
        $this->assertStringContainsString('كتب بوصفه مؤلفًا: 1', $res->json('errors.person.0'));
        $this->assertNull($author->fresh()->deleted_at);
    }

    public function test_unused_person_is_archived_then_restored_by_admin(): void
    {
        $admin = $this->admin();
        $person = Person::factory()->create();

        $this->as($admin)->deleteJson("/api/v1/persons/{$person->id}")->assertNoContent();
        $this->assertSoftDeleted($person);
        $this->assertNotContains($person->id, $this->ids($this->as($admin)->getJson('/api/v1/persons')));
        $this->assertContains($person->id, $this->ids($this->as($admin)->getJson('/api/v1/persons?status=trashed')));
        $this->as($this->researcher)->getJson('/api/v1/persons?status=trashed')->assertForbidden();

        $this->as($admin)->postJson("/api/v1/persons/{$person->id}/restore")->assertOk();
        $this->assertNull($person->fresh()->deleted_at);
        $this->assertDatabaseHas('activity_logs', ['event' => 'persons.archived']);
        $this->assertDatabaseHas('activity_logs', ['event' => 'persons.restored']);
    }

    public function test_merged_people_are_hidden_from_lists_and_cannot_be_restored_or_used(): void
    {
        $primary = Person::factory()->create();
        $merged = Person::factory()->create(['merged_into_person_id' => $primary->id]);

        $this->assertNotContains($merged->id, $this->ids($this->as($this->researcher)->getJson('/api/v1/persons')));
        $this->as($this->researcher)->getJson("/api/v1/persons/{$merged->id}")
            ->assertOk()->assertJsonPath('data.merged_into_person_id', $primary->id);

        $this->as($this->researcher)->postJson('/api/v1/books', ['title' => 'ك', 'author_person_id' => $merged->id])
            ->assertStatus(422)->assertJsonValidationErrors(['author_person_id']);
    }

    public function test_filters_by_death_range_status_and_sorts(): void
    {
        $early = Person::factory()->published()->create(['death_year_h' => 100, 'primary_name' => 'ب']);
        $late = Person::factory()->published()->create(['death_year_h' => 300, 'primary_name' => 'أ']);
        $draft = Person::factory()->create(['death_year_h' => 150]);

        $range = $this->ids($this->as($this->researcher)->getJson('/api/v1/persons?death_from=120&death_to=400&sort=death'));
        $this->assertSame([$draft->id, $late->id], $range);

        $this->assertSame([$early->id, $late->id], $this->ids($this->as($this->researcher)->getJson('/api/v1/persons?publication_status=published&sort=death')));
        $this->assertSame([$late->id, $early->id], $this->ids($this->as($this->researcher)->getJson('/api/v1/persons?publication_status=published')));

        $this->as($this->researcher)->getJson('/api/v1/persons?per_page=1000')->assertStatus(422);
        $this->as($this->researcher)->getJson('/api/v1/persons?publication_status=bogus')->assertStatus(422);
    }

    public function test_reader_cannot_widen_visibility_with_status_filters(): void
    {
        $draft = Person::factory()->create();
        $this->publishedPerson();

        $this->assertNotContains($draft->id, $this->ids($this->as($this->reader)->getJson('/api/v1/persons?verification_status=draft&publication_status=unpublished')));
    }

    public function test_pagination_shape(): void
    {
        Person::factory()->count(5)->published()->create();

        $this->as($this->reader)->getJson('/api/v1/persons?per_page=2')->assertOk()
            ->assertJsonCount(2, 'data')->assertJsonPath('meta.total', 5)->assertJsonPath('meta.per_page', 2);
    }

    // ----- المتشابهون: اقتراح فقط -----

    public function test_similar_suggests_candidates_but_changes_nothing(): void
    {
        $base = Person::factory()->create(['primary_name' => 'محمد بن إسماعيل البخاري', 'name_search' => 'محمد بن اسماعيل البخاري', 'death_year_h' => 256]);
        $twin = Person::factory()->create(['primary_name' => 'مُحَمَّد بن إِسْمَاعِيل البُخَارِيّ', 'name_search' => 'محمد بن اسماعيل البخاري', 'death_year_h' => 256]);
        $far = Person::factory()->create(['primary_name' => 'محمد بن إسماعيل', 'name_search' => 'محمد بن اسماعيل', 'death_year_h' => 700]);
        Person::factory()->create(['primary_name' => 'خالد', 'name_search' => 'خالد']);

        $before = [Person::count(), Person::whereNotNull('merged_into_person_id')->count(), PersonMergeSuggestion::count(), ActivityLog::count()];
        $res = $this->as($this->researcher)->getJson("/api/v1/persons/{$base->id}/similar")->assertOk();

        $rows = collect($res->json('data'));
        $this->assertSame($twin->id, $rows->first()['person']['id']);
        $this->assertGreaterThanOrEqual(90, $rows->first()['score']);
        $this->assertContains('الاسم المطبَّع متطابق', $rows->first()['reasons']);
        $byId = $rows->keyBy(fn ($r) => $r['person']['id']);
        $this->assertTrue(! isset($byId[$far->id]) || $byId[$far->id]['score'] < $rows->first()['score'], 'التباعد في سنة الوفاة يُضعف الاحتمال');
        $this->assertNotContains($base->id, $rows->pluck('person.id')->all(), 'لا يقترح الشخص لنفسه');

        $this->assertSame($before, [Person::count(), Person::whereNotNull('merged_into_person_id')->count(), PersonMergeSuggestion::count(), ActivityLog::count()], 'الاقتراح لا يكتب أي شيء');
    }

    public function test_similar_never_leaks_hidden_people_to_reader(): void
    {
        $base = $this->publishedPerson(['primary_name' => 'سفيان الثوري', 'name_search' => 'سفيان الثوري']);
        $hidden = Person::factory()->create(['primary_name' => 'سفيان الثوري', 'name_search' => 'سفيان الثوري']);
        $merged = $this->publishedPerson(['name_search' => 'سفيان الثوري', 'merged_into_person_id' => $base->id]);

        $reader = $this->ids($this->as($this->reader)->getJson("/api/v1/persons/{$base->id}/similar"));
        $reader = collect($this->as($this->reader)->getJson("/api/v1/persons/{$base->id}/similar")->json('data'))->pluck('person.id')->all();
        $this->assertNotContains($hidden->id, $reader);
        $this->assertNotContains($merged->id, $reader);

        $team = collect($this->as($this->researcher)->getJson("/api/v1/persons/{$base->id}/similar")->json('data'))->pluck('person.id')->all();
        $this->assertContains($hidden->id, $team);
    }

    public function test_similar_uses_name_variants_and_ignores_particles_only_matches(): void
    {
        $base = Person::factory()->create(['name_search' => 'عبد الله بن المبارك']);
        $viaVariant = Person::factory()->create(['name_search' => 'ابن المبارك المروزي']);
        $viaVariant->nameVariants()->create(['name' => 'عبد الله بن المبارك', 'name_search' => 'عبد الله بن المبارك']);
        $particles = Person::factory()->create(['name_search' => 'بن بن']);

        $ids = collect($this->as($this->researcher)->getJson("/api/v1/persons/{$base->id}/similar")->json('data'))->pluck('person.id')->all();
        $this->assertContains($viaVariant->id, $ids);
        $this->assertNotContains($particles->id, $ids);
    }

    public function test_create_response_carries_similar_people_for_the_researcher(): void
    {
        $this->publishedPerson(['primary_name' => 'الأوزاعي', 'name_search' => 'الاوزاعي']);

        $this->as($this->researcher)->postJson('/api/v1/persons', ['primary_name' => 'الأَوزَاعِيّ'])
            ->assertCreated()->assertJsonCount(1, 'similar')->assertJsonPath('similar.0.score', 100);
    }

    // ----- علاقات الشيوخ والتلاميذ -----

    public function test_adds_lists_and_removes_teacher_relations(): void
    {
        $student = $this->publishedPerson();
        $teacher = $this->publishedPerson();

        $res = $this->as($this->researcher)->postJson("/api/v1/persons/{$student->id}/relations", [
            'teacher_person_id' => $teacher->id, 'relation_type' => 'reading',
        ])->assertCreated()->assertJsonPath('data.person.id', $teacher->id)->assertJsonPath('data.relation_type.value', 'reading');

        $this->as($this->reader)->getJson("/api/v1/persons/{$student->id}/relations")->assertOk()
            ->assertJsonPath('data.teachers.0.person.id', $teacher->id)->assertJsonCount(0, 'data.students');
        $this->as($this->reader)->getJson("/api/v1/persons/{$teacher->id}/relations")->assertOk()
            ->assertJsonPath('data.students.0.person.id', $student->id);

        $this->as($this->researcher)->deleteJson("/api/v1/persons/{$student->id}/relations/".$res->json('data.id'))->assertNoContent();
        $this->assertSame(0, PersonRelation::count());
    }

    public function test_relation_rules_self_duplicate_and_hidden_teacher(): void
    {
        $student = $this->publishedPerson();
        $teacher = $this->publishedPerson();

        $this->as($this->researcher)->postJson("/api/v1/persons/{$student->id}/relations", ['teacher_person_id' => $student->id])
            ->assertStatus(422)->assertJsonValidationErrors(['teacher_person_id']);

        $this->as($this->researcher)->postJson("/api/v1/persons/{$student->id}/relations", ['teacher_person_id' => $teacher->id, 'relation_type' => 'hearing'])->assertCreated();
        $this->as($this->researcher)->postJson("/api/v1/persons/{$student->id}/relations", ['teacher_person_id' => $teacher->id, 'relation_type' => 'hearing'])
            ->assertStatus(422)->assertJsonValidationErrors(['relation_type']);
        $this->as($this->researcher)->postJson("/api/v1/persons/{$student->id}/relations", ['teacher_person_id' => $teacher->id, 'relation_type' => 'ijaza'])
            ->assertCreated();
        $this->assertSame(2, PersonRelation::count(), 'صور الأخذ المختلفة بين الشخصين نفسيهما مسموحة');

        $this->as($this->researcher)->postJson("/api/v1/persons/{$student->id}/relations", ['teacher_person_id' => 999999])
            ->assertStatus(422);
    }

    public function test_relations_hide_unpublished_counterparts_from_reader(): void
    {
        $student = $this->publishedPerson();
        $hiddenTeacher = Person::factory()->create();
        PersonRelation::create(['teacher_person_id' => $hiddenTeacher->id, 'student_person_id' => $student->id]);

        $this->as($this->reader)->getJson("/api/v1/persons/{$student->id}/relations")->assertOk()->assertJsonCount(0, 'data.teachers');
        $this->as($this->researcher)->getJson("/api/v1/persons/{$student->id}/relations")->assertJsonCount(1, 'data.teachers');
    }

    public function test_deleting_a_relation_that_belongs_to_someone_else_is_404(): void
    {
        $a = $this->publishedPerson();
        $b = $this->publishedPerson();
        $c = $this->publishedPerson();
        $relation = PersonRelation::create(['teacher_person_id' => $b->id, 'student_person_id' => $c->id]);

        $this->as($this->researcher)->deleteJson("/api/v1/persons/{$a->id}/relations/{$relation->id}")->assertNotFound();
        $this->assertSame(1, PersonRelation::count());
    }
}
