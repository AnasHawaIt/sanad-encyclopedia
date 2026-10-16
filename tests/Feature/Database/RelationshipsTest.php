<?php

namespace Tests\Feature\Database;

use App\Enums\ReferenceStatus;
use App\Enums\ReviewAction;
use App\Models\ActivityLog;
use App\Models\Asnad;
use App\Models\Attachment;
use App\Models\Book;
use App\Models\Ijaza;
use App\Models\IjazaType;
use App\Models\Person;
use App\Models\Source;
use App\Models\SubjectCategory;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Str;
use Tests\DatabaseTestCase;

class RelationshipsTest extends DatabaseTestCase
{
    public function test_morph_map_is_registered(): void
    {
        $this->assertSame(
            Person::class,
            Relation::getMorphedModel('person'),
            'سجّل App\Providers\MorphMapServiceProvider في bootstrap/providers.php'
        );
    }

    public function test_a_book_can_have_many_asnads_and_an_asnad_many_books(): void
    {
        $bookA = Book::factory()->create();
        $bookB = Book::factory()->create();
        $asnad1 = Asnad::factory()->create();
        $asnad2 = Asnad::factory()->create();

        $bookA->asnads()->attach([$asnad1->id, $asnad2->id]);
        $bookB->asnads()->attach($asnad1->id);

        $this->assertCount(2, $bookA->asnads);
        $this->assertEqualsCanonicalizing([$bookA->id, $bookB->id], $asnad1->books->pluck('id')->all());

        // الربط نفسه لا يتكرر
        $this->assertRejectedByDatabase(fn () => $bookA->asnads()->attach($asnad1->id));
    }

    public function test_an_ijaza_links_to_many_books_and_asnads(): void
    {
        $ijaza = Ijaza::factory()->create();
        $books = Book::factory()->count(2)->create();
        $asnads = Asnad::factory()->count(2)->create();

        $ijaza->books()->attach($books->pluck('id')->all());
        $ijaza->asnads()->attach($asnads->pluck('id')->all());

        $this->assertCount(2, $ijaza->books);
        $this->assertCount(2, $ijaza->asnads);
        $this->assertTrue($books[0]->ijazas->first()->is($ijaza));
        $this->assertTrue($asnads[0]->ijazas->first()->is($ijaza));

        $this->assertRejectedByDatabase(fn () => $ijaza->books()->attach($books[0]->id));
        $this->assertRejectedByDatabase(fn () => $ijaza->asnads()->attach($asnads[0]->id));
    }

    public function test_ijaza_keeps_grantor_recipient_type_and_original_text(): void
    {
        $type = IjazaType::create(['name' => 'نوع إجازة تجريبي']);
        $ijaza = Ijaza::factory()->create([
            'ijaza_type_id' => $type->id,
            'original_text' => "نص أصلي\nبسطرين",
            'revised_text' => 'نص منقح منفصل',
        ]);

        $fresh = $ijaza->fresh();

        $this->assertSame("نص أصلي\nبسطرين", $fresh->original_text);
        $this->assertSame('نص منقح منفصل', $fresh->revised_text);
        $this->assertTrue($fresh->type->is($type));
        $this->assertTrue($fresh->grantor->grantedIjazas->first()->is($ijaza));
        $this->assertTrue($fresh->recipient->receivedIjazas->first()->is($ijaza));
    }

    public function test_a_book_may_have_no_documented_author_and_may_have_a_category(): void
    {
        $category = SubjectCategory::create(['name' => 'تصنيف تجريبي']);
        $book = Book::factory()->withoutAuthor()->create(['subject_category_id' => $category->id]);

        $this->assertNull($book->author);
        $this->assertTrue($book->category->is($category));

        $withAuthor = Book::factory()->create();
        $this->assertTrue($withAuthor->author->authoredBooks->first()->is($withAuthor));
    }

    public function test_source_references_attach_to_different_entities_using_morph_aliases(): void
    {
        $source = Source::factory()->create();
        $person = Person::factory()->create();
        $asnad = Asnad::factory()->create();

        $reference = $person->sourceReferences()->create([
            'source_id' => $source->id,
            'volume' => '2',
            'page' => '12',
            'quote_text' => 'اقتباس تجريبي',
        ]);
        $asnad->sourceReferences()->create(['source_id' => $source->id, 'page' => '3']);

        $this->assertDatabaseHas('source_references', ['referenceable_type' => 'person', 'referenceable_id' => $person->id]);
        $this->assertDatabaseHas('source_references', ['referenceable_type' => 'asnad', 'referenceable_id' => $asnad->id]);
        $this->assertTrue($reference->fresh()->referenceable->is($person));
        $this->assertSame(ReferenceStatus::Unverified, $reference->fresh()->reference_status);
        $this->assertSame(2, $source->references()->count());
    }

    public function test_a_source_with_references_cannot_be_hard_deleted(): void
    {
        $source = Source::factory()->create();
        Person::factory()->create()->sourceReferences()->create(['source_id' => $source->id]);

        $this->assertRejectedByDatabase(fn () => $source->forceDelete());
    }

    public function test_attachments_link_to_many_entities_without_duplicates(): void
    {
        $attachment = Attachment::create([
            'original_filename' => 'وثيقة.pdf',
            'stored_path' => 'attachments/'.Str::uuid().'.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 1024,
            'checksum' => hash('sha256', 'محتوى اختبار'),
        ]);
        $person = Person::factory()->create();
        $asnad = Asnad::factory()->create();

        $person->attachments()->attach($attachment->id, ['sort_order' => 1]);
        $asnad->attachments()->attach($attachment->id);

        $this->assertDatabaseHas('attachables', ['attachable_type' => 'person', 'attachable_id' => $person->id]);
        $this->assertDatabaseHas('attachables', ['attachable_type' => 'asnad', 'attachable_id' => $asnad->id]);
        $this->assertSame(1, $person->attachments()->first()->pivot->sort_order);
        $this->assertSame('local', $attachment->fresh()->disk);

        $this->assertRejectedByDatabase(fn () => $person->attachments()->attach($attachment->id));
    }

    public function test_reviews_and_activity_logs_are_append_only_records_tied_to_entities(): void
    {
        $user = User::factory()->create();
        $book = Book::factory()->create();

        $book->reviews()->create([
            'reviewer_id' => $user->id,
            'action' => ReviewAction::Submit,
            'from_status' => 'draft',
            'to_status' => 'in_review',
            'notes' => 'ملاحظة تجريبية',
        ]);

        $log = ActivityLog::create([
            'user_id' => $user->id,
            'event' => 'book.created',
            'subject_type' => 'book',
            'subject_id' => $book->id,
            'changes' => ['title' => [null, $book->title]],
        ]);

        $this->assertDatabaseHas('reviews', ['reviewable_type' => 'book', 'reviewable_id' => $book->id]);
        $this->assertSame(ReviewAction::Submit, $book->reviews()->first()->action);
        $this->assertTrue($log->fresh()->subject->is($book));
        $this->assertSame([null, $book->title], $log->fresh()->changes['title']);
    }

    public function test_soft_deleting_a_book_keeps_its_links_and_restoring_brings_it_back(): void
    {
        $book = Book::factory()->create();
        $asnad = Asnad::factory()->create();
        $book->asnads()->attach($asnad->id);

        $book->delete();

        $this->assertSoftDeleted($book);
        $this->assertDatabaseHas('book_asnad', ['book_id' => $book->id, 'asnad_id' => $asnad->id]);

        $trashed = Book::withTrashed()->find($book->id);
        $this->assertCount(1, $trashed->asnads);

        $trashed->restore();
        $this->assertNull($trashed->fresh()->deleted_at);
    }

    public function test_lookup_names_are_unique_and_disabled_values_are_kept(): void
    {
        $type = IjazaType::create(['name' => 'نوع فريد تجريبي']);

        $this->assertRejectedByDatabase(fn () => IjazaType::create(['name' => 'نوع فريد تجريبي']));

        $type->update(['is_active' => false]);

        $this->assertSame(0, IjazaType::active()->count());
        $this->assertSame(1, IjazaType::count());
    }
}
