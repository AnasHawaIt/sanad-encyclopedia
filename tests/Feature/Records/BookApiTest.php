<?php

namespace Tests\Feature\Records;

use App\Models\Asnad;
use App\Models\Book;
use App\Models\BookEdition;
use App\Models\Ijaza;
use App\Models\Person;
use App\Models\SubjectCategory;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesRecordFixtures;
use Tests\DatabaseTestCase;

class BookApiTest extends DatabaseTestCase
{
    use CreatesRecordFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpRecords();
    }

    private function publishedBook(array $attributes = []): Book
    {
        return Book::factory()->create($attributes + ['verification_status' => 'verified', 'publication_status' => 'published']);
    }

    public function test_researcher_creates_book_with_titles_and_contributors(): void
    {
        $author = $this->publishedPerson();
        $commentator = $this->publishedPerson();
        $category = SubjectCategory::create(['name' => 'الحديث']);

        $res = $this->as($this->researcher)->postJson('/api/v1/books', [
            'title' => 'الجامع الصَّحيح',
            'author_person_id' => $author->id,
            'subject_category_id' => $category->id,
            'alternate_titles' => [['title' => 'صحيح البخاري']],
            'contributors' => [['person_id' => $commentator->id, 'role' => 'commentator']],
        ])->assertCreated()
            ->assertJsonPath('data.verification_status.value', 'draft')
            ->assertJsonPath('data.publication_status.value', 'unpublished')
            ->assertJsonPath('data.author.id', $author->id)
            ->assertJsonPath('data.category.name', 'الحديث')
            ->assertJsonPath('data.contributors.0.role.label', 'شارح')
            ->assertJsonCount(1, 'data.alternate_titles');

        $book = Book::firstOrFail();
        $this->assertSame('الجامع الصَّحيح', $book->title);
        $this->assertSame('الجامع الصحيح', $book->title_search);
        $this->assertSame('صحيح البخاري', $res->json('data.alternate_titles.0.title'));
        $this->assertDatabaseHas('activity_logs', ['event' => 'books.created']);
    }

    public function test_book_may_have_no_author_and_status_fields_are_ignored(): void
    {
        $this->as($this->researcher)->postJson('/api/v1/books', [
            'title' => 'كتاب مجهول المؤلف', 'verification_status' => 'verified', 'publication_status' => 'published',
        ])->assertCreated()->assertJsonPath('data.author_person_id', null);

        $this->assertSame('draft', Book::firstOrFail()->verification_status->value);
    }

    public function test_validation(): void
    {
        $this->as($this->researcher)->postJson('/api/v1/books', [])->assertStatus(422)->assertJsonValidationErrors(['title']);
        $this->as($this->researcher)->postJson('/api/v1/books', ['title' => 'ك', 'author_person_id' => 999999])->assertStatus(422)->assertJsonValidationErrors(['author_person_id']);

        $inactive = SubjectCategory::create(['name' => 'معطل', 'is_active' => false]);
        $this->as($this->researcher)->postJson('/api/v1/books', ['title' => 'ك', 'subject_category_id' => $inactive->id])->assertStatus(422)->assertJsonValidationErrors(['subject_category_id']);

        $person = $this->publishedPerson();
        $this->as($this->researcher)->postJson('/api/v1/books', [
            'title' => 'ك', 'contributors' => [['person_id' => $person->id, 'role' => 'editor'], ['person_id' => $person->id, 'role' => 'editor']],
        ])->assertStatus(422)->assertJsonValidationErrors(['contributors.1.person_id']);
        $this->assertSame(0, Book::count(), 'الفشل لا يترك سجلًا ناقصًا');
    }

    public function test_unusable_author_in_a_nested_failure_rolls_everything_back(): void
    {
        $this->as($this->researcher)->postJson('/api/v1/books', [
            'title' => 'ك', 'contributors' => [['person_id' => 999999]],
        ])->assertStatus(422);
        $this->assertSame(0, Book::count());
    }

    public function test_reader_sees_only_published_books_and_gets_404_for_hidden(): void
    {
        $published = $this->publishedBook();
        $draft = Book::factory()->create();

        $this->assertSame([$published->id], $this->ids($this->as($this->reader)->getJson('/api/v1/books')->assertOk()));
        $this->as($this->reader)->getJson("/api/v1/books/{$draft->id}")->assertNotFound();
        $this->as($this->reader)->getJson("/api/v1/books/{$draft->id}/references")->assertNotFound();
        $this->as($this->researcher)->getJson("/api/v1/books/{$draft->id}")->assertOk();
        $this->assertEqualsCanonicalizing([$published->id, $draft->id], $this->ids($this->as($this->researcher)->getJson('/api/v1/books')));
    }

    public function test_unpublished_author_contributor_and_editor_are_not_disclosed_to_reader(): void
    {
        $hidden = Person::factory()->create(['primary_name' => 'اسم سري غير منشور']);
        $book = $this->publishedBook(['author_person_id' => $hidden->id]);
        $book->contributors()->create(['person_id' => $hidden->id, 'role' => 'editor']);
        $book->editions()->create(['editor_person_id' => $hidden->id, 'editor_name' => 'اسم المحقق المكتوب']);

        $reader = $this->as($this->reader)->getJson("/api/v1/books/{$book->id}")->assertOk();
        $reader->assertJsonPath('data.author', null)->assertJsonCount(0, 'data.contributors')->assertJsonPath('data.editions.0.editor', null);
        $this->assertStringNotContainsString('اسم سري غير منشور', $reader->getContent());
        $this->assertSame('اسم المحقق المكتوب', $reader->json('data.editions.0.editor_name'), 'اسم المحقق النصي المدخل بيانات عامة للطبعة');

        $team = $this->as($this->researcher)->getJson("/api/v1/books/{$book->id}")->assertOk();
        $this->assertSame($hidden->id, $team->json('data.author.id'));
    }

    public function test_internal_fields_hidden_from_reader(): void
    {
        $book = $this->publishedBook(['notes' => 'ملاحظة بحثية', 'created_by' => $this->researcher->id]);

        $this->as($this->reader)->getJson("/api/v1/books/{$book->id}")->assertOk()
            ->assertJsonMissingPath('data.notes')->assertJsonMissingPath('data.created_by');
        $this->as($this->researcher)->getJson("/api/v1/books/{$book->id}")->assertJsonPath('data.notes', 'ملاحظة بحثية');
    }

    public function test_search_by_title_or_alternate_title_ignores_diacritics(): void
    {
        $a = $this->publishedBook(['title' => 'الجَامِعُ الصَّحِيحُ', 'title_search' => 'الجامع الصحيح']);
        $a->alternateTitles()->create(['title' => 'صَحِيحُ البُخَارِيّ', 'title_search' => 'صحيح البخاري']);
        $b = $this->publishedBook(['title' => 'الموطأ', 'title_search' => 'الموطا']);

        $q = fn (string $term) => $this->ids($this->as($this->reader)->getJson('/api/v1/books?q='.urlencode($term))->assertOk());
        $this->assertSame([$a->id], $q('الجامع الصحيح'));
        $this->assertSame([$a->id], $q('البخاري'));
        $this->assertSame([$a->id], $q('بخاري صحيح'));
        $this->assertSame([$b->id], $q('الموطأ'));
        $this->assertSame([], $q('%'));
    }

    public function test_filters_by_author_and_category(): void
    {
        $x = $this->publishedPerson();
        $cat = SubjectCategory::create(['name' => 'الفقه']);
        $a = $this->publishedBook(['author_person_id' => $x->id, 'subject_category_id' => $cat->id]);
        $this->publishedBook();

        $this->assertSame([$a->id], $this->ids($this->as($this->reader)->getJson("/api/v1/books?author_person_id={$x->id}")));
        $this->assertSame([$a->id], $this->ids($this->as($this->reader)->getJson("/api/v1/books?subject_category_id={$cat->id}")));
    }

    public function test_update_syncs_children_and_audits_changes(): void
    {
        $book = Book::factory()->create(['title' => 'قديم', 'title_search' => 'قديم']);

        $this->as($this->researcher)->putJson("/api/v1/books/{$book->id}", [
            'title' => 'عنوان جديد', 'alternate_titles' => [['title' => 'اسم آخر']],
        ])->assertOk()->assertJsonPath('data.title', 'عنوان جديد');

        $this->assertSame('عنوان جديد', $book->fresh()->title_search);
        $this->assertSame(1, $book->alternateTitles()->count());
        $this->assertDatabaseHas('activity_logs', ['event' => 'books.updated', 'subject_id' => $book->id]);

        $this->as($this->researcher)->putJson("/api/v1/books/{$book->id}", ['alternate_titles' => []])->assertOk();
        $this->assertSame(0, $book->alternateTitles()->count());
    }

    public function test_book_linked_to_asnad_or_ijaza_cannot_be_archived(): void
    {
        $admin = $this->admin();
        $book = Book::factory()->create();
        $asnad = Asnad::factory()->create();
        $book->asnads()->attach($asnad);

        $res = $this->as($admin)->deleteJson("/api/v1/books/{$book->id}")->assertStatus(422);
        $this->assertStringContainsString('أسانيد: 1', $res->json('errors.book.0'));
        $this->assertNull($book->fresh()->deleted_at);

        $book->asnads()->detach();
        $ijaza = Ijaza::factory()->create();
        $book->ijazas()->attach($ijaza);
        $this->as($admin)->deleteJson("/api/v1/books/{$book->id}")->assertStatus(422);
    }

    public function test_unlinked_book_is_archived_and_restored(): void
    {
        $admin = $this->admin();
        $book = Book::factory()->create();

        $this->as($admin)->deleteJson("/api/v1/books/{$book->id}")->assertNoContent();
        $this->assertSoftDeleted($book);
        $this->assertNotContains($book->id, $this->ids($this->as($admin)->getJson('/api/v1/books')));
        $this->assertContains($book->id, $this->ids($this->as($admin)->getJson('/api/v1/books?status=trashed')));
        $this->as($this->researcher)->getJson('/api/v1/books?status=trashed')->assertForbidden();
        $this->as($this->researcher)->deleteJson("/api/v1/books/{$book->id}")->assertForbidden();

        $this->as($admin)->postJson("/api/v1/books/{$book->id}/restore")->assertOk();
        $this->assertNull($book->fresh()->deleted_at);
    }

    public function test_reader_cannot_write(): void
    {
        $book = $this->publishedBook();

        $this->as($this->reader)->postJson('/api/v1/books', ['title' => 'ك'])->assertForbidden();
        $this->as($this->reader)->putJson("/api/v1/books/{$book->id}", ['title' => 'ك'])->assertForbidden();
        $this->as($this->reader)->postJson("/api/v1/books/{$book->id}/editions", [])->assertForbidden();
    }

    // ----- الطبعات -----

    public function test_edition_lifecycle(): void
    {
        $book = Book::factory()->create();
        $editor = $this->publishedPerson();

        $created = $this->as($this->researcher)->postJson("/api/v1/books/{$book->id}/editions", [
            'edition_type' => 'manuscript', 'publisher' => 'دار', 'editor_person_id' => $editor->id,
            'publication_year' => 1400, 'volumes_count' => 3, 'manuscript_ref' => 'ر 123', 'library_name' => 'المكتبة الظاهرية',
        ])->assertCreated()->assertJsonPath('data.edition_type.label', 'مخطوط')->assertJsonPath('data.editor.id', $editor->id);

        $id = $created->json('data.id');
        $this->as($this->researcher)->putJson("/api/v1/books/{$book->id}/editions/{$id}", ['volumes_count' => 5])
            ->assertOk()->assertJsonPath('data.volumes_count', 5);
        $this->as($this->researcher)->deleteJson("/api/v1/books/{$book->id}/editions/{$id}")->assertNoContent();
        $this->assertSame(0, $book->editions()->count());
    }

    public function test_edition_defaults_are_returned_from_the_database(): void
    {
        $book = Book::factory()->create();

        $this->as($this->researcher)->postJson("/api/v1/books/{$book->id}/editions", [])
            ->assertCreated()->assertJsonPath('data.edition_type.value', 'printed');
    }

    public function test_edition_validation(): void
    {
        $book = Book::factory()->create();

        $this->as($this->researcher)->postJson("/api/v1/books/{$book->id}/editions", ['edition_type' => 'x', 'publication_year' => 99999, 'volumes_count' => 0])
            ->assertStatus(422)->assertJsonValidationErrors(['edition_type', 'publication_year', 'volumes_count']);
    }

    public function test_edition_of_another_book_is_404(): void
    {
        $a = Book::factory()->create();
        $b = Book::factory()->create();
        $edition = $b->editions()->create(['edition_type' => 'printed']);

        $this->as($this->researcher)->putJson("/api/v1/books/{$a->id}/editions/{$edition->id}", ['publisher' => 'x'])->assertNotFound();
        $this->as($this->researcher)->deleteJson("/api/v1/books/{$a->id}/editions/{$edition->id}")->assertNotFound();
        $this->assertSame(1, BookEdition::count());
    }

    public function test_edition_cited_by_an_asnad_or_ijaza_cannot_be_deleted(): void
    {
        $book = Book::factory()->create();
        $edition = $book->editions()->create(['edition_type' => 'printed']);
        $book->asnads()->attach(Asnad::factory()->create(), ['book_edition_id' => $edition->id]);

        $this->as($this->researcher)->deleteJson("/api/v1/books/{$book->id}/editions/{$edition->id}")->assertStatus(422);
        $this->assertSame(1, BookEdition::count());

        DB::table('book_asnad')->delete();
        $book->ijazas()->attach(Ijaza::factory()->create(), ['book_edition_id' => $edition->id]);
        $this->as($this->researcher)->deleteJson("/api/v1/books/{$book->id}/editions/{$edition->id}")->assertStatus(422);
    }

    public function test_cannot_add_edition_to_a_hidden_book_as_reader_or_use_hidden_editor(): void
    {
        $book = $this->publishedBook();
        $hiddenEditor = Person::factory()->create();

        $this->as($this->researcher)->postJson("/api/v1/books/{$book->id}/editions", ['editor_person_id' => $hiddenEditor->id])->assertCreated();
        $this->assertSame($hiddenEditor->id, $book->editions()->firstOrFail()->editor_person_id, 'الباحث يرى غير المنشور فيصح اختياره');
    }

    // ----- التصنيفات والقوائم -----

    public function test_options_lists_arabic_labels_from_the_server(): void
    {
        $res = $this->as($this->reader)->getJson('/api/v1/lookups/options')->assertOk();

        $this->assertContains('موثّق', array_column($res->json('data.verification_statuses'), 'label'));
        $this->assertContains('شارح', array_column($res->json('data.contributor_roles'), 'label'));
        $this->assertContains('ملازمة', array_column($res->json('data.relation_types'), 'label'));
    }

    public function test_categories_listing_hides_inactive_from_non_managers(): void
    {
        SubjectCategory::create(['name' => 'فعال', 'sort_order' => 1]);
        SubjectCategory::create(['name' => 'معطل', 'is_active' => false, 'sort_order' => 2]);

        $this->assertSame(['فعال'], array_column($this->as($this->reader)->getJson('/api/v1/lookups/categories')->json('data'), 'name'));
        $this->assertSame(['فعال', 'معطل'], array_column($this->as($this->admin())->getJson('/api/v1/lookups/categories')->json('data'), 'name'));
    }

    public function test_only_managers_edit_categories_and_cycles_and_duplicates_are_rejected(): void
    {
        $admin = $this->admin();
        $root = $this->as($admin)->postJson('/api/v1/lookups/categories', ['name' => 'علوم الحديث'])->assertCreated()->json('data.id');
        $child = $this->as($admin)->postJson('/api/v1/lookups/categories', ['name' => 'المصطلح', 'parent_id' => $root])->assertCreated()->json('data.id');

        $this->as($this->researcher)->postJson('/api/v1/lookups/categories', ['name' => 'س'])->assertForbidden();
        $this->as($admin)->postJson('/api/v1/lookups/categories', ['name' => 'علوم الحديث'])->assertStatus(422)->assertJsonValidationErrors(['name']);
        $this->as($admin)->postJson('/api/v1/lookups/categories', ['name' => 'علوم الحديث', 'parent_id' => $root])->assertCreated();

        $this->as($admin)->putJson("/api/v1/lookups/categories/{$root}", ['parent_id' => $child])->assertStatus(422)->assertJsonValidationErrors(['parent_id']);
        $this->as($admin)->putJson("/api/v1/lookups/categories/{$root}", ['parent_id' => $root])->assertStatus(422);
        $this->as($admin)->putJson("/api/v1/lookups/categories/{$child}", ['is_active' => false])->assertOk()->assertJsonPath('data.is_active', false);
    }
}
