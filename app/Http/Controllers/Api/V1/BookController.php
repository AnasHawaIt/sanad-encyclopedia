<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\FindsRecords;
use App\Http\Controllers\Controller;
use App\Http\Requests\Books\BookRequest;
use App\Http\Resources\BookResource;
use App\Models\Book;
use App\Models\User;
use App\Services\Books\BookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BookController extends Controller
{
    use FindsRecords;

    public function __construct(private BookService $books) {}

    public function index(Request $request)
    {
        $request->validate([
            'q' => ['sometimes', 'nullable', 'string', 'max:200'],
            'status' => ['sometimes', 'nullable', 'in:trashed'],
            'author_person_id' => ['sometimes', 'nullable', 'integer'],
            'subject_category_id' => ['sometimes', 'nullable', 'integer'],
            'sort' => ['sometimes', 'nullable', 'in:title,recent'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $user = $request->user();
        $query = Book::query()->visibleTo($user)->with($this->relations($user, false));

        if ($request->input('status') === 'trashed') {
            abort_unless($this->seesArchived($request, 'books.delete'), 403);
            $query->onlyTrashed();
        }
        if ($term = $request->input('q')) {
            $query->searchTitle($term);
        }
        if ($author = $request->input('author_person_id')) {
            $query->where('author_person_id', $author);
        }
        if ($category = $request->input('subject_category_id')) {
            $query->where('subject_category_id', $category);
        }

        $request->input('sort', 'title') === 'recent' ? $query->orderByDesc('id') : $query->orderBy('title');
        $query->orderBy('id');

        return BookResource::collection($query->paginate($request->integer('per_page', 25)));
    }

    public function show(Request $request, int $book): BookResource
    {
        $model = $this->findVisible(Book::class, $book, $request, $this->seesArchived($request, 'books.delete'));

        return new BookResource($model->load($this->relations($request->user())));
    }

    public function store(BookRequest $request): JsonResponse
    {
        $book = $this->books->create($request->user(), $request->validated());

        return (new BookResource($book->load($this->relations($request->user()))))->response()->setStatusCode(201);
    }

    public function update(BookRequest $request, int $book): BookResource
    {
        $model = $this->findVisible(Book::class, $book, $request);
        $updated = $this->books->update($request->user(), $model, $request->validated());

        return new BookResource($updated->load($this->relations($request->user())));
    }

    public function destroy(Request $request, int $book): JsonResponse
    {
        $this->books->archive($request->user(), $this->findVisible(Book::class, $book, $request));

        return response()->json(null, 204);
    }

    public function restore(Request $request, int $book): BookResource
    {
        $model = Book::onlyTrashed()->findOrFail($book);
        $restored = $this->books->restore($request->user(), $model);

        return new BookResource($restored->load($this->relations($request->user())));
    }

    /**
     * العلاقات بقيد الرؤية: مؤلف أو مساهم أو محقق غير منشور لا يظهر لمن لا يملك صلاحية رؤيته.
     *
     * @return array<int|string, mixed>
     */
    private function relations(?User $user, bool $full = true): array
    {
        $visible = fn ($q) => $q->visibleTo($user);
        $with = ['author' => $visible, 'category'];
        if ($full) {
            $with += [
                'alternateTitles',
                'contributors.person' => $visible,
                'editions.editor' => $visible,
            ];
        }

        return $with;
    }
}
