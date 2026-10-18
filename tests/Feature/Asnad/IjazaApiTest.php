<?php

namespace Tests\Feature\Asnad;

use App\Models\ActivityLog;
use App\Models\Asnad;
use App\Models\Book;
use App\Models\BookEdition;
use App\Models\ChainRole;
use App\Models\Ijaza;
use App\Models\IjazaType;
use App\Models\Person;
use App\Models\User;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\CreatesRecordFixtures;
use Tests\DatabaseTestCase;

class IjazaApiTest extends DatabaseTestCase
{
    use CreatesRecordFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpRecords();
    }

    private function published(array $attributes = []): Ijaza
    {
        return Ijaza::factory()->create($attributes + ['verification_status' => 'verified', 'publication_status' => 'published']);
    }

    private function publishedBook(): Book
    {
        return Book::factory()->create(['verification_status' => 'verified', 'publication_status' => 'published']);
    }

    private function publishedAsnad(): Asnad
    {
        return Asnad::factory()->create(['verification_status' => 'verified', 'publication_status' => 'published']);
    }

    // ---------------------------------------------------------------- إنشاء وتعديل

    public function test_create_normalizes_search_text_keeps_original_and_audits(): void
    {
        $grantor = $this->publishedPerson();
        $recipient = $this->publishedPerson();
        $type = IjazaType::create(['name' => 'إجازة عامة']);

        $this->as($this->researcher)->postJson('/api/v1/ijazas', [
            'ijaza_number' => 'ج-12', 'ijaza_type_id' => $type->id,
            'grantor_person_id' => $grantor->id, 'recipient_person_id' => $recipient->id,
            'original_text' => 'أَجَزْتُ لَهُ رِوَايَةَ الكُتُبِ السِّتَّةِ',
            'ijaza_year_h' => 1320, 'verification_status' => 'verified', 'publication_status' => 'published',
        ])->assertCreated()
            ->assertJsonPath('data.verification_status.value', 'draft')
            ->assertJsonPath('data.publication_status.value', 'unpublished')
            ->assertJsonPath('data.grantor.id', $grantor->id);

        $ijaza = Ijaza::firstOrFail();
        $this->assertSame('أَجَزْتُ لَهُ رِوَايَةَ الكُتُبِ السِّتَّةِ', $ijaza->original_text);
        $this->assertSame('اجزت له روايه الكتب السته', $ijaza->text_search);
        $this->assertSame($this->researcher->id, $ijaza->created_by);
        $this->assertDatabaseHas('activity_logs', ['event' => 'ijazas.created', 'user_id' => $this->researcher->id]);
    }

    public function test_original_text_is_locked_but_revised_text_is_editable_and_searchable(): void
    {
        $ijaza = Ijaza::factory()->create(['original_text' => 'نص أصلي فيه خطأ مطبعي', 'text_search' => 'نص اصلي فيه خطا مطبعي']);
        $url = "/api/v1/ijazas/{$ijaza->id}";

        $this->as($this->researcher)->putJson($url, ['original_text' => 'نص آخر'])->assertStatus(422)->assertJsonValidationErrors(['original_text']);
        $this->as($this->researcher)->putJson($url, ['revised_text' => 'نص مصحح فيه تصحيح فريد'])->assertOk();

        $fresh = $ijaza->fresh();
        $this->assertSame('نص أصلي فيه خطأ مطبعي', $fresh->original_text);
        $this->assertSame('نص مصحح فيه تصحيح فريد', $fresh->revised_text);
        $q = fn (string $t) => $this->ids($this->as($this->researcher)->getJson('/api/v1/ijazas?q='.urlencode($t)));
        $this->assertSame([$ijaza->id], $q('تصحيح فريد'));
        $this->assertSame([$ijaza->id], $q('مطبعي'), 'البحث يشمل الأصلي أيضًا');
    }

    public function test_hijri_and_gregorian_dates_are_independent_and_validated(): void
    {
        $res = $this->as($this->researcher)->postJson('/api/v1/ijazas', ['ijaza_year_h' => 1100, 'ijaza_date_text' => 'في شهر رجب'])->assertCreated();
        $this->assertNull($res->json('data.ijaza_date_gregorian'), 'لا تحويل تلقائي');

        $this->as($this->researcher)->postJson('/api/v1/ijazas', ['ijaza_date_gregorian' => '1900-05-01'])->assertCreated()->assertJsonPath('data.ijaza_year_h', null);
        $this->as($this->researcher)->postJson('/api/v1/ijazas', ['ijaza_year_h' => 5000])->assertStatus(422);
        $this->as($this->researcher)->postJson('/api/v1/ijazas', ['ijaza_date_gregorian' => '01/05/1900'])->assertStatus(422);
    }

    public function test_invalid_people_and_inactive_type_are_rejected(): void
    {
        $merged = Person::factory()->create(['merged_into_person_id' => Person::factory()->create()->id]);
        $off = IjazaType::create(['name' => 'معطل', 'is_active' => false]);

        $this->as($this->researcher)->postJson('/api/v1/ijazas', ['grantor_person_id' => $merged->id])->assertStatus(422)->assertJsonValidationErrors(['grantor_person_id']);
        $this->as($this->researcher)->postJson('/api/v1/ijazas', ['recipient_person_id' => 99999])->assertStatus(422);
        $this->as($this->researcher)->postJson('/api/v1/ijazas', ['ijaza_type_id' => $off->id])->assertStatus(422);
    }

    public function test_unchanged_update_is_not_audited(): void
    {
        $ijaza = Ijaza::factory()->create(['summary' => 'ملخص']);
        ActivityLog::query()->delete();

        $this->as($this->researcher)->putJson("/api/v1/ijazas/{$ijaza->id}", ['summary' => 'ملخص'])->assertOk();
        $this->assertSame(0, ActivityLog::where('event', 'ijazas.updated')->count());
        $this->as($this->researcher)->putJson("/api/v1/ijazas/{$ijaza->id}", ['summary' => 'ملخص جديد'])->assertOk();
        $this->assertSame(1, ActivityLog::where('event', 'ijazas.updated')->count());
    }

    // ---------------------------------------------------------------- الرؤية

    public function test_reader_sees_only_published_and_hidden_is_404(): void
    {
        $shown = $this->published();
        $draft = Ijaza::factory()->create();

        $this->assertSame([$shown->id], $this->ids($this->as($this->reader)->getJson('/api/v1/ijazas')->assertOk()));
        $this->as($this->reader)->getJson("/api/v1/ijazas/{$draft->id}")->assertNotFound();
        $this->as($this->reader)->getJson("/api/v1/ijazas/{$draft->id}/references")->assertNotFound();
        $this->as($this->researcher)->getJson("/api/v1/ijazas/{$draft->id}")->assertOk();
        $this->assertNotContains($draft->id, $this->ids($this->as($this->reader)->getJson('/api/v1/ijazas?verification_status=draft')));
    }

    public function test_hidden_grantor_and_recipient_are_not_disclosed_to_reader(): void
    {
        $hidden = Person::factory()->create(['primary_name' => 'مجيز غير منشور سري']);
        $shown = $this->publishedPerson();
        $ijaza = $this->published(['grantor_person_id' => $hidden->id, 'recipient_person_id' => $shown->id]);

        $res = $this->as($this->reader)->getJson("/api/v1/ijazas/{$ijaza->id}")->assertOk();
        $this->assertNull($res->json('data.grantor'));
        $this->assertNull($res->json('data.grantor_person_id'));
        $this->assertSame($shown->id, $res->json('data.recipient.id'));
        $this->assertStringNotContainsString('مجيز غير منشور سري', $res->getContent());

        $this->assertSame($hidden->id, $this->as($this->researcher)->getJson("/api/v1/ijazas/{$ijaza->id}")->json('data.grantor.id'));
        $list = $this->as($this->reader)->getJson('/api/v1/ijazas')->assertOk();
        $this->assertStringNotContainsString('مجيز غير منشور سري', $list->getContent());
    }

    public function test_internal_fields_and_nested_link_notes_are_hidden_from_reader(): void
    {
        $ijaza = $this->published(['notes' => 'ملاحظة داخلية', 'created_by' => $this->researcher->id]);
        $book = $this->publishedBook();
        $asnad = $this->publishedAsnad();
        $ijaza->books()->attach($book, ['notes' => 'سر الكتاب']);
        $ijaza->asnads()->attach($asnad, ['notes' => 'سر السند']);

        $reader = $this->as($this->reader)->getJson("/api/v1/ijazas/{$ijaza->id}")->assertOk()->assertJsonMissingPath('data.notes')->assertJsonMissingPath('data.created_by');
        $this->assertArrayNotHasKey('notes', $reader->json('data.books.0'));
        $this->assertArrayNotHasKey('notes', $reader->json('data.asnads.0'));
        $this->assertStringNotContainsString('سر الكتاب', $reader->getContent());

        $team = $this->as($this->researcher)->getJson("/api/v1/ijazas/{$ijaza->id}");
        $this->assertSame('سر الكتاب', $team->json('data.books.0.notes'));
    }

    public function test_reader_cannot_write(): void
    {
        $ijaza = $this->published();

        $this->as($this->reader)->postJson('/api/v1/ijazas', [])->assertForbidden();
        $this->as($this->reader)->putJson("/api/v1/ijazas/{$ijaza->id}", ['summary' => 'x'])->assertForbidden();
        $this->as($this->reader)->putJson("/api/v1/ijazas/{$ijaza->id}/asnads", ['items' => []])->assertForbidden();
        $this->as($this->reader)->putJson("/api/v1/ijazas/{$ijaza->id}/books", ['items' => []])->assertForbidden();
        $this->as($this->reader)->deleteJson("/api/v1/ijazas/{$ijaza->id}")->assertForbidden();
    }

    // ---------------------------------------------------------------- بحث وفلترة

    public function test_search_and_filters(): void
    {
        $g = $this->publishedPerson();
        $r = $this->publishedPerson();
        $type = IjazaType::create(['name' => 'إجازة قراءة']);
        $a = $this->published(['original_text' => 'أجزته بالقراءة', 'text_search' => 'اجزته بالقراءه'.'', 'ijaza_year_h' => 1200, 'grantor_person_id' => $g->id, 'ijaza_type_id' => $type->id]);
        $b = $this->published(['original_text' => 'نص آخر', 'text_search' => 'نص اخر', 'ijaza_year_h' => 1300, 'recipient_person_id' => $r->id]);
        $book = $this->publishedBook();
        $asnad = $this->publishedAsnad();
        $b->books()->attach($book);
        $a->asnads()->attach($asnad);

        $f = fn (string $query) => $this->ids($this->as($this->reader)->getJson('/api/v1/ijazas?'.$query)->assertOk());
        $this->assertSame([$a->id], $f('q='.urlencode('أَجَزْتُه')));
        $this->assertSame([], $f('q='.urlencode('%')));
        $this->assertSame([$a->id], $f("grantor_person_id={$g->id}"));
        $this->assertSame([$b->id], $f("recipient_person_id={$r->id}"));
        $this->assertSame([$a->id], $f("ijaza_type_id={$type->id}"));
        $this->assertSame([$b->id], $f('year_from=1250'));
        $this->assertSame([$a->id], $f('year_to=1250'));
        $this->assertSame([$b->id], $f("book_id={$book->id}"));
        $this->assertSame([$a->id], $f("asnad_id={$asnad->id}"));
        $this->assertSame([$a->id, $b->id], $f('sort=year'));
        $this->as($this->reader)->getJson('/api/v1/ijazas?sort=bad')->assertStatus(422);
    }

    // ---------------------------------------------------------------- أرشفة

    public function test_archive_and_restore_need_delete_permission_and_work_even_when_linked(): void
    {
        $admin = $this->admin();
        $ijaza = Ijaza::factory()->create();
        $ijaza->books()->attach(Book::factory()->create());
        $ijaza->asnads()->attach(Asnad::factory()->create());

        $this->as($this->researcher)->deleteJson("/api/v1/ijazas/{$ijaza->id}")->assertForbidden();
        $this->as($admin)->deleteJson("/api/v1/ijazas/{$ijaza->id}")->assertNoContent();
        $this->assertSoftDeleted($ijaza);
        $this->assertSame(1, $ijaza->books()->count(), 'الروابط تبقى');
        $this->assertNotContains($ijaza->id, $this->ids($this->as($admin)->getJson('/api/v1/ijazas')));

        $this->as($admin)->postJson("/api/v1/ijazas/{$ijaza->id}/restore")->assertOk();
        $this->assertNull($ijaza->fresh()->deleted_at);
    }

    // ---------------------------------------------------------------- الروابط

    public function test_sync_ijaza_asnads_replaces_the_list_and_audits(): void
    {
        $ijaza = Ijaza::factory()->create();
        [$a, $b, $c] = [Asnad::factory()->create(), Asnad::factory()->create(), Asnad::factory()->create()];
        $url = "/api/v1/ijazas/{$ijaza->id}/asnads";

        $this->as($this->researcher)->putJson($url, ['items' => [['asnad_id' => $a->id, 'notes' => 'ن'], ['asnad_id' => $b->id]]])->assertOk();
        $this->assertEqualsCanonicalizing([$a->id, $b->id], $ijaza->asnads()->pluck('asnads.id')->all());

        $this->as($this->researcher)->putJson($url, ['items' => [['asnad_id' => $c->id], ['asnad_id' => $b->id]]])->assertOk();
        $this->assertEqualsCanonicalizing([$b->id, $c->id], $ijaza->asnads()->pluck('asnads.id')->all());

        $this->as($this->researcher)->putJson($url, ['items' => []])->assertOk();
        $this->assertSame(0, $ijaza->asnads()->count());
        $this->assertGreaterThanOrEqual(2, ActivityLog::where('event', 'like', 'ijazas.%')->where('subject_id', $ijaza->id)->count());
    }

    public function test_duplicates_and_unknown_targets_are_rejected_and_change_nothing(): void
    {
        $ijaza = Ijaza::factory()->create();
        $keep = Asnad::factory()->create();
        $ijaza->asnads()->attach($keep);
        $url = "/api/v1/ijazas/{$ijaza->id}/asnads";

        $this->as($this->researcher)->putJson($url, ['items' => [['asnad_id' => $keep->id], ['asnad_id' => $keep->id]]])->assertStatus(422);
        $this->as($this->researcher)->putJson($url, ['items' => [['asnad_id' => 99999]]])->assertStatus(422);
        $this->as($this->researcher)->putJson($url, [])->assertStatus(422)->assertJsonValidationErrors(['items']);
        $this->assertSame([$keep->id], $ijaza->asnads()->pluck('asnads.id')->all());
    }

    public function test_edition_must_belong_to_the_linked_book(): void
    {
        $ijaza = Ijaza::factory()->create();
        $book = Book::factory()->create();
        $other = Book::factory()->create();
        $mine = BookEdition::create(['book_id' => $book->id, 'edition_type' => 'printed']);
        $foreign = BookEdition::create(['book_id' => $other->id, 'edition_type' => 'printed']);
        $url = "/api/v1/ijazas/{$ijaza->id}/books";

        $this->as($this->researcher)->putJson($url, ['items' => [['book_id' => $book->id, 'book_edition_id' => $foreign->id]]])->assertStatus(422);
        $this->assertSame(0, $ijaza->books()->count());
        $this->as($this->researcher)->putJson($url, ['items' => [['book_id' => $book->id, 'book_edition_id' => $mine->id]]])->assertOk();
        $this->assertSame($mine->id, $ijaza->books()->first()->pivot->book_edition_id);
    }

    public function test_book_asnads_sync_requires_books_update_and_validates_edition(): void
    {
        $book = Book::factory()->create();
        $asnad = Asnad::factory()->create();
        $edition = BookEdition::create(['book_id' => Book::factory()->create()->id, 'edition_type' => 'printed']);
        $url = "/api/v1/books/{$book->id}/asnads";

        $this->as($this->reader)->putJson($url, ['items' => []])->assertForbidden();
        $this->as($this->researcher)->putJson($url, ['items' => [['asnad_id' => $asnad->id, 'book_edition_id' => $edition->id]]])->assertStatus(422);
        $this->as($this->researcher)->putJson($url, ['items' => [['asnad_id' => $asnad->id]]])->assertOk();
        $this->assertSame([$asnad->id], $book->asnads()->pluck('asnads.id')->all());
        $this->as($this->researcher)->getJson("/api/v1/asnads?book_id={$book->id}")->assertOk()->assertJsonPath('meta.total', 1);
    }

    public function test_hidden_targets_cannot_be_linked_by_a_limited_user_and_hidden_links_survive_a_sync(): void
    {
        // دور محدود: يعدّل الإجازات لكنه لا يرى غير المنشور
        $role = Role::create(['name' => 'limited-editor', 'guard_name' => 'web']);
        $role->givePermissionTo(['ijazas.view', 'ijazas.update', 'asnads.view']);
        $limited = User::factory()->create(['is_active' => true]);
        $limited->assignRole($role);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $ijaza = $this->published();
        $visible = $this->publishedAsnad();
        $hidden = Asnad::factory()->create();
        $ijaza->asnads()->attach($hidden, ['notes' => 'رابط مخفي']);
        $url = "/api/v1/ijazas/{$ijaza->id}/asnads";

        $this->as($limited)->putJson($url, ['items' => [['asnad_id' => $hidden->id]]])->assertStatus(422);
        $this->as($limited)->putJson($url, ['items' => [['asnad_id' => $visible->id]]])->assertOk();

        $this->assertEqualsCanonicalizing([$hidden->id, $visible->id], $ijaza->asnads()->pluck('asnads.id')->all(), 'الرابط المخفي لم يُفصل');
        $this->assertSame('رابط مخفي', $ijaza->asnads()->where('asnads.id', $hidden->id)->first()->pivot->notes);
    }

    // ---------------------------------------------------------------- تحذيرات

    public function test_warning_when_linked_books_have_no_asnad_and_only_for_the_team(): void
    {
        $ijaza = $this->published();
        $book = $this->publishedBook();
        $ijaza->books()->attach($book);

        $team = $this->as($this->researcher)->getJson("/api/v1/ijazas/{$ijaza->id}")->assertOk();
        $this->assertContains('books_without_asnad', array_column($team->json('warnings') ?? $team->json('data.warnings') ?? [], 'code'));
        $reader = $this->as($this->reader)->getJson("/api/v1/ijazas/{$ijaza->id}")->assertOk();
        $this->assertEmpty($reader->json('warnings') ?? $reader->json('data.warnings') ?? []);

        $ijaza->asnads()->attach($this->publishedAsnad());
        $after = $this->as($this->researcher)->getJson("/api/v1/ijazas/{$ijaza->id}");
        $this->assertNotContains('books_without_asnad', array_column($after->json('warnings') ?? $after->json('data.warnings') ?? [], 'code'));
    }

    public function test_asnad_cannot_be_archived_while_linked_to_an_ijaza(): void
    {
        $asnad = Asnad::factory()->create();
        Ijaza::factory()->create()->asnads()->attach($asnad);

        $this->as($this->admin())->deleteJson("/api/v1/asnads/{$asnad->id}")->assertStatus(422);
    }

    // ---------------------------------------------------------------- القوائم

    public function test_lookup_sets_list_create_toggle_and_guard(): void
    {
        $admin = $this->admin();
        $on = IjazaType::create(['name' => 'فعّال']);
        $off = IjazaType::create(['name' => 'معطّل', 'is_active' => false]);

        $names = fn ($user) => array_column($this->as($user)->getJson('/api/v1/lookups/sets/ijaza-types')->assertOk()->json('data'), 'name');
        $this->assertSame(['فعّال'], $names($this->reader));
        $this->assertEqualsCanonicalizing(['فعّال', 'معطّل'], $names($admin));

        $this->as($admin)->postJson('/api/v1/lookups/sets/ijaza-types', ['name' => 'فعّال'])->assertStatus(422);
        $this->as($admin)->postJson('/api/v1/lookups/sets/chain-roles', ['name' => 'شيخ'])->assertCreated();
        $this->as($admin)->putJson("/api/v1/lookups/sets/ijaza-types/{$off->id}", ['is_active' => true])->assertOk();
        $this->assertTrue($off->fresh()->is_active);
        $this->as($admin)->getJson('/api/v1/lookups/sets/nonsense')->assertNotFound();
        $this->as($this->researcher)->postJson('/api/v1/lookups/sets/ijaza-types', ['name' => 'جديد'])->assertForbidden();
        $this->assertSame(1, ChainRole::where('name', 'شيخ')->count());
    }

    public function test_guests_get_401(): void
    {
        app('auth')->forgetGuards();
        $this->getJson('/api/v1/ijazas')->assertStatus(401);
        $this->postJson('/api/v1/ijazas', [])->assertStatus(401);
        $this->putJson('/api/v1/ijazas/1/books', ['items' => []])->assertStatus(401);
        $this->getJson('/api/v1/lookups/sets/ijaza-types')->assertStatus(401);
    }
}
