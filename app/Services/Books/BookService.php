<?php

namespace App\Services\Books;

use App\Models\Book;
use App\Models\BookEdition;
use App\Models\User;
use App\Services\Audit\ActivityLogger;
use App\Services\Concerns\SyncsChildren;
use App\Services\Persons\PersonAccess;
use App\Services\Search\ArabicNormalizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BookService
{
    use SyncsChildren;

    private const FIELDS = ['title', 'author_person_id', 'subject_category_id', 'summary', 'description', 'notes'];

    private const EDITION_FIELDS = [
        'edition_type', 'publisher', 'editor_person_id', 'editor_name',
        'publication_year', 'volumes_count', 'manuscript_ref', 'library_name', 'notes',
    ];

    public function __construct(
        private ActivityLogger $log,
        private ArabicNormalizer $normalizer,
        private PersonAccess $access,
    ) {}

    public function create(User $actor, array $data): Book
    {
        $this->assertPeopleUsable($actor, $data);

        return DB::transaction(function () use ($actor, $data) {
            $book = new Book(array_intersect_key($data, array_flip(self::FIELDS)));
            $book->title_search = $this->normalizer->normalize($book->title);
            $book->created_by = $actor->getKey();
            $book->updated_by = $actor->getKey();
            $book->save();

            $this->syncTitles($book, $data['alternate_titles'] ?? []);
            $this->syncContributors($book, $data['contributors'] ?? []);

            $this->log->record('books.created', $book, ['title' => $book->title], $actor);

            return $this->loaded($book->refresh());
        });
    }

    public function update(User $actor, Book $book, array $data): Book
    {
        $this->assertPeopleUsable($actor, $data);

        return DB::transaction(function () use ($actor, $book, $data) {
            $book->fill(array_intersect_key($data, array_flip(self::FIELDS)));
            $book->title_search = $this->normalizer->normalize($book->title);
            $book->updated_by = $actor->getKey();
            $book->save();

            $changes = $this->diff($book, ['title_search']);
            if (array_key_exists('alternate_titles', $data) && $this->syncTitles($book, $data['alternate_titles'])) {
                $changes['alternate_titles'] = 'updated';
            }
            if (array_key_exists('contributors', $data) && $this->syncContributors($book, $data['contributors'])) {
                $changes['contributors'] = 'updated';
            }
            if ($changes !== []) {
                $this->log->record('books.updated', $book, $changes, $actor);
            }

            return $this->loaded($book->refresh());
        });
    }

    /** لا يُؤرشف كتاب له أسانيد أو إجازات مرتبطة؛ تُفكّ الروابط أولًا بقرار واعٍ. */
    public function archive(User $actor, Book $book): void
    {
        $usage = array_filter([
            'asnads' => DB::table('book_asnad')->where('book_id', $book->getKey())->count(),
            'ijazas' => DB::table('ijaza_book')->where('book_id', $book->getKey())->count(),
        ]);
        if ($usage !== []) {
            $details = collect($usage)->map(fn (int $n, string $k) => __("records.counts.{$k}").": {$n}")->implode('، ');

            throw ValidationException::withMessages(['book' => __('records.book_in_use', ['details' => $details])]);
        }

        DB::transaction(function () use ($actor, $book) {
            $book->delete();
            $this->log->record('books.archived', $book, ['title' => $book->title], $actor);
        });
    }

    public function restore(User $actor, Book $book): Book
    {
        return DB::transaction(function () use ($actor, $book) {
            $book->restore();
            $this->log->record('books.restored', $book, ['title' => $book->title], $actor);

            return $this->loaded($book->refresh());
        });
    }

    public function addEdition(User $actor, Book $book, array $data): BookEdition
    {
        $this->access->assertUsable($actor, [$data['editor_person_id'] ?? null], 'editor_person_id');

        return DB::transaction(function () use ($actor, $book, $data) {
            $edition = $book->editions()->create(
                array_intersect_key($data, array_flip(self::EDITION_FIELDS)) + ['created_by' => $actor->getKey()]
            );
            $this->log->record('books.edition_added', $book, ['edition_id' => $edition->getKey()], $actor);

            // refresh: قيم قاعدة البيانات الافتراضية (مثل edition_type) لا تكون على النسخة قبل إعادة التحميل
            return $edition->refresh();
        });
    }

    public function updateEdition(User $actor, Book $book, BookEdition $edition, array $data): BookEdition
    {
        $this->access->assertUsable($actor, [$data['editor_person_id'] ?? null], 'editor_person_id');

        return DB::transaction(function () use ($actor, $book, $edition, $data) {
            $edition->fill(array_intersect_key($data, array_flip(self::EDITION_FIELDS)))->save();
            $changes = $this->diff($edition);
            if ($changes !== []) {
                $this->log->record('books.edition_updated', $book, ['edition_id' => $edition->getKey()] + $changes, $actor);
            }

            return $edition->refresh();
        });
    }

    /** طبعة مذكورة في سند أو إجازة لا تُحذف، لأن ذلك يُفقد السند معرفة أي طبعة يقصد. */
    public function deleteEdition(User $actor, Book $book, BookEdition $edition): void
    {
        $used = DB::table('book_asnad')->where('book_edition_id', $edition->getKey())->exists()
            || DB::table('ijaza_book')->where('book_edition_id', $edition->getKey())->exists();
        if ($used) {
            throw ValidationException::withMessages(['edition' => __('records.edition_in_use')]);
        }

        DB::transaction(function () use ($actor, $book, $edition) {
            $edition->delete();
            $this->log->record('books.edition_deleted', $book, ['edition_id' => $edition->getKey()], $actor);
        });
    }

    private function assertPeopleUsable(User $actor, array $data): void
    {
        $this->access->assertUsable($actor, [$data['author_person_id'] ?? null], 'author_person_id');
        $this->access->assertUsable($actor, array_column($data['contributors'] ?? [], 'person_id'), 'contributors');
    }

    private function syncTitles(Book $book, array $items): bool
    {
        return $this->syncChildren($book->alternateTitles(), $items, fn (array $i) => [
            'title' => $i['title'],
            'title_search' => $this->normalizer->normalize($i['title']),
            'notes' => $i['notes'] ?? null,
        ]);
    }

    private function syncContributors(Book $book, array $items): bool
    {
        return $this->syncChildren($book->contributors(), $items, fn (array $i) => [
            'person_id' => $i['person_id'],
            'role' => $i['role'] ?? 'contributor',
            'notes' => $i['notes'] ?? null,
        ]);
    }

    private function loaded(Book $book): Book
    {
        return $book->load(['alternateTitles', 'contributors', 'editions', 'category']);
    }
}
