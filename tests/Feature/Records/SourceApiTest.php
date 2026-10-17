<?php

namespace Tests\Feature\Records;

use App\Models\ActivityLog;
use App\Models\Book;
use App\Models\Source;
use App\Models\SourceReference;
use Tests\Concerns\CreatesRecordFixtures;
use Tests\DatabaseTestCase;

class SourceApiTest extends DatabaseTestCase
{
    use CreatesRecordFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpRecords();
    }

    public function test_researcher_creates_source_with_search_columns_and_default_type(): void
    {
        $this->as($this->researcher)->postJson('/api/v1/sources', [
            'title' => 'سِيَرُ أَعْلَامِ النُّبَلَاء', 'author' => 'الذَّهَبِيّ', 'url' => 'https://example.test/siyar',
        ])->assertCreated()->assertJsonPath('data.source_type.value', 'book')->assertJsonPath('data.references_count', 0);

        $source = Source::firstOrFail();
        $this->assertSame('سِيَرُ أَعْلَامِ النُّبَلَاء', $source->title);
        $this->assertSame('سير اعلام النبلاء', $source->title_search);
        $this->assertSame('الذهبي', $source->author_search);
        $this->assertSame($this->researcher->id, $source->created_by);
    }

    public function test_url_must_be_http_or_https_and_is_optional(): void
    {
        foreach (['javascript:alert(1)', 'ftp://x.test/a', 'not a url', 'data:text/html,<script>1</script>'] as $bad) {
            $this->as($this->researcher)->postJson('/api/v1/sources', ['title' => 'م', 'url' => $bad])
                ->assertStatus(422)->assertJsonValidationErrors(['url']);
        }
        $this->as($this->researcher)->postJson('/api/v1/sources', ['title' => 'م'])->assertCreated();
        $this->as($this->researcher)->postJson('/api/v1/sources', ['title' => 'م', 'url' => 'http://example.test'])->assertCreated();
        $this->assertSame(0, Source::whereIn('url', ['javascript:alert(1)'])->count());
    }

    public function test_source_validation(): void
    {
        $this->as($this->researcher)->postJson('/api/v1/sources', [])->assertStatus(422)->assertJsonValidationErrors(['title']);
        $this->as($this->researcher)->postJson('/api/v1/sources', ['title' => 'م', 'source_type' => 'x'])->assertStatus(422)->assertJsonValidationErrors(['source_type']);
    }

    public function test_search_and_filter_sources(): void
    {
        $a = $this->as($this->researcher)->postJson('/api/v1/sources', ['title' => 'تَهْذِيبُ الكَمَال', 'author' => 'المِزِّيّ'])->json('data.id');
        $b = $this->as($this->researcher)->postJson('/api/v1/sources', ['title' => 'رسالة جامعية', 'source_type' => 'thesis'])->json('data.id');

        $q = fn (string $query) => $this->ids($this->as($this->reader)->getJson('/api/v1/sources?'.$query)->assertOk());
        $this->assertSame([$a], $q('q='.urlencode('تهذيب الكمال')));
        $this->assertSame([$a], $q('q='.urlencode('المزي')));
        $this->assertSame([$b], $q('source_type=thesis'));
        $this->assertSame([], $q('q='.urlencode('%')));
    }

    public function test_update_keeps_search_columns_in_sync_and_audits(): void
    {
        $id = $this->as($this->researcher)->postJson('/api/v1/sources', ['title' => 'قديم'])->json('data.id');

        $this->as($this->researcher)->putJson("/api/v1/sources/{$id}", ['title' => 'عُنوان جَديد', 'author' => 'مُؤلِّف'])->assertOk();

        $source = Source::findOrFail($id);
        $this->assertSame('عنوان جديد', $source->title_search);
        $this->assertSame('مولف', $source->author_search);
        $this->assertDatabaseHas('activity_logs', ['event' => 'sources.updated', 'subject_id' => $id]);
    }

    public function test_reader_may_view_but_not_change_sources(): void
    {
        $source = Source::factory()->create();

        $this->as($this->reader)->getJson('/api/v1/sources')->assertOk();
        $this->as($this->reader)->getJson("/api/v1/sources/{$source->id}")->assertOk();
        $this->as($this->reader)->postJson('/api/v1/sources', ['title' => 'م'])->assertForbidden();
        $this->as($this->reader)->putJson("/api/v1/sources/{$source->id}", ['title' => 'م'])->assertForbidden();
        $this->as($this->reader)->deleteJson("/api/v1/sources/{$source->id}")->assertForbidden();
    }

    public function test_internal_source_fields_hidden_from_reader(): void
    {
        $source = Source::factory()->create(['notes' => 'ملاحظة', 'created_by' => $this->researcher->id]);

        $this->as($this->reader)->getJson("/api/v1/sources/{$source->id}")->assertJsonMissingPath('data.notes')->assertJsonMissingPath('data.created_by');
        $this->as($this->researcher)->getJson("/api/v1/sources/{$source->id}")->assertJsonPath('data.notes', 'ملاحظة');
    }

    public function test_source_with_references_cannot_be_archived_but_unused_can(): void
    {
        $admin = $this->admin();
        $used = Source::factory()->create();
        $person = $this->publishedPerson();
        $person->sourceReferences()->create(['source_id' => $used->id]);
        $free = Source::factory()->create();

        $res = $this->as($admin)->deleteJson("/api/v1/sources/{$used->id}")->assertStatus(422);
        $this->assertStringContainsString('1 مرجعًا', $res->json('errors.source.0'));

        $this->as($admin)->deleteJson("/api/v1/sources/{$free->id}")->assertNoContent();
        $this->assertSoftDeleted($free);
        $this->as($admin)->postJson("/api/v1/sources/{$free->id}/restore")->assertOk();
        $this->as($this->researcher)->deleteJson("/api/v1/sources/{$free->id}")->assertForbidden();
    }

    // ----- المراجع -----

    public function test_researcher_adds_reference_to_person_and_it_is_always_unverified(): void
    {
        $person = $this->publishedPerson();
        $source = Source::factory()->create();

        $this->as($this->researcher)->postJson("/api/v1/persons/{$person->id}/references", [
            'source_id' => $source->id, 'volume' => '2', 'page' => '15', 'quote_text' => 'نص منقول', 'reference_status' => 'verified',
        ])->assertCreated()->assertJsonPath('data.reference_status.value', 'unverified')->assertJsonPath('data.source.id', $source->id);

        $ref = SourceReference::firstOrFail();
        $this->assertSame($person->getMorphClass(), $ref->referenceable_type);
        $this->assertSame('person', $ref->referenceable_type);
        $this->assertSame('unverified', $ref->reference_status->value);
        $this->assertDatabaseHas('activity_logs', ['event' => 'references.created', 'subject_id' => $person->id]);
    }

    public function test_reference_on_a_book_uses_the_book_morph_alias(): void
    {
        $book = Book::factory()->create();
        $source = Source::factory()->create();

        $this->as($this->researcher)->postJson("/api/v1/books/{$book->id}/references", ['source_id' => $source->id, 'page' => '7'])->assertCreated();
        $this->assertSame('book', SourceReference::firstOrFail()->referenceable_type);
        $this->as($this->researcher)->getJson("/api/v1/books/{$book->id}/references")->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_reference_validation(): void
    {
        $person = $this->publishedPerson();
        $gone = Source::factory()->create();
        $gone->delete();

        $this->as($this->researcher)->postJson("/api/v1/persons/{$person->id}/references", [])->assertStatus(422)->assertJsonValidationErrors(['source_id']);
        $this->as($this->researcher)->postJson("/api/v1/persons/{$person->id}/references", ['source_id' => $gone->id])->assertStatus(422)->assertJsonValidationErrors(['source_id']);
        $this->as($this->researcher)->postJson("/api/v1/persons/{$person->id}/references", ['source_id' => 99999])->assertStatus(422);
    }

    public function test_only_reviewer_can_verify_or_reject_a_reference(): void
    {
        $person = $this->publishedPerson();
        $ref = $person->sourceReferences()->create(['source_id' => Source::factory()->create()->id]);
        $url = "/api/v1/persons/{$person->id}/references/{$ref->id}/status";

        $this->as($this->researcher)->putJson($url, ['reference_status' => 'verified'])->assertForbidden();
        $this->as($this->reader)->putJson($url, ['reference_status' => 'verified'])->assertForbidden();
        $this->assertSame('unverified', $ref->fresh()->reference_status->value);

        $this->as($this->reviewer)->putJson($url, ['reference_status' => 'verified'])->assertOk()->assertJsonPath('data.reference_status.value', 'verified');
        $this->as($this->reviewer)->putJson($url, ['reference_status' => 'rejected'])->assertOk();
        $this->as($this->reviewer)->putJson($url, ['reference_status' => 'bogus'])->assertStatus(422);

        $log = ActivityLog::where('event', 'references.status_changed')->latest('id')->firstOrFail();
        $this->assertSame($this->reviewer->id, $log->user_id);
        $this->assertSame('verified', $log->changes['reference_status']['from']);
    }

    public function test_editing_content_of_a_verified_reference_resets_it_to_unverified(): void
    {
        $person = $this->publishedPerson();
        $ref = $person->sourceReferences()->create(['source_id' => Source::factory()->create()->id, 'page' => '1', 'reference_status' => 'verified']);
        $base = "/api/v1/persons/{$person->id}/references/{$ref->id}";

        $this->as($this->researcher)->putJson($base, ['page' => '1'])->assertOk()->assertJsonPath('data.reference_status.value', 'verified');
        $this->as($this->researcher)->putJson($base, ['page' => '99'])->assertOk()->assertJsonPath('data.reference_status.value', 'unverified');
        $this->as($this->researcher)->putJson($base, ['page' => '5', 'reference_status' => 'verified'])->assertOk()->assertJsonPath('data.reference_status.value', 'unverified');
    }

    public function test_reader_sees_only_verified_references_on_published_records(): void
    {
        $person = $this->publishedPerson();
        $source = Source::factory()->create();
        $person->sourceReferences()->create(['source_id' => $source->id, 'page' => 'محقق', 'reference_status' => 'verified', 'note' => 'ملاحظة داخلية']);
        $person->sourceReferences()->create(['source_id' => $source->id, 'page' => 'غير محقق']);
        $person->sourceReferences()->create(['source_id' => $source->id, 'page' => 'مرفوض', 'reference_status' => 'rejected']);

        $reader = $this->as($this->reader)->getJson("/api/v1/persons/{$person->id}/references")->assertOk();
        $this->assertSame(['محقق'], array_column($reader->json('data'), 'page'));
        $this->assertStringNotContainsString('ملاحظة داخلية', $reader->getContent());

        $this->as($this->researcher)->getJson("/api/v1/persons/{$person->id}/references")->assertJsonCount(3, 'data');
    }

    public function test_reader_cannot_write_references(): void
    {
        $person = $this->publishedPerson();
        $source = Source::factory()->create();
        $ref = $person->sourceReferences()->create(['source_id' => $source->id]);

        $this->as($this->reader)->postJson("/api/v1/persons/{$person->id}/references", ['source_id' => $source->id])->assertForbidden();
        $this->as($this->reader)->putJson("/api/v1/persons/{$person->id}/references/{$ref->id}", ['page' => '1'])->assertForbidden();
        $this->as($this->reader)->deleteJson("/api/v1/persons/{$person->id}/references/{$ref->id}")->assertForbidden();
    }

    public function test_reference_of_another_record_is_404_and_unknown_type_is_not_routable(): void
    {
        $a = $this->publishedPerson();
        $b = $this->publishedPerson();
        $ref = $b->sourceReferences()->create(['source_id' => Source::factory()->create()->id]);

        $this->as($this->researcher)->putJson("/api/v1/persons/{$a->id}/references/{$ref->id}", ['page' => '1'])->assertNotFound();
        $this->as($this->researcher)->deleteJson("/api/v1/persons/{$a->id}/references/{$ref->id}")->assertNotFound();
        $this->as($this->researcher)->getJson("/api/v1/ijazas/{$a->id}/references")->assertNotFound();
        $this->assertSame(1, SourceReference::count());
    }

    public function test_reference_can_be_removed_and_audited(): void
    {
        $person = $this->publishedPerson();
        $ref = $person->sourceReferences()->create(['source_id' => Source::factory()->create()->id]);

        $this->as($this->researcher)->deleteJson("/api/v1/persons/{$person->id}/references/{$ref->id}")->assertNoContent();
        $this->assertSame(0, SourceReference::count());
        $this->assertDatabaseHas('activity_logs', ['event' => 'references.deleted', 'subject_id' => $person->id]);
    }

    public function test_guests_get_401_on_all_phase4_endpoints(): void
    {
        foreach (['persons', 'books', 'sources', 'lookups/options', 'lookups/categories', 'persons/1', 'persons/1/similar', 'persons/1/references'] as $uri) {
            $this->getJson("/api/v1/{$uri}")->assertStatus(401);
        }
        $this->postJson('/api/v1/persons', [])->assertStatus(401);
        $this->postJson('/api/v1/books', [])->assertStatus(401);
        $this->postJson('/api/v1/sources', [])->assertStatus(401);
    }

    public function test_unknown_person_id_is_404_not_500(): void
    {
        $this->as($this->researcher)->getJson('/api/v1/persons/999999')->assertNotFound();
        $this->as($this->researcher)->getJson('/api/v1/persons/abc')->assertNotFound();
        $this->as($this->researcher)->getJson('/api/v1/books/999999')->assertNotFound();
        $this->as($this->researcher)->getJson('/api/v1/sources/999999')->assertNotFound();
    }
}
