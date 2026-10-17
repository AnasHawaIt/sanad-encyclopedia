<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\FindsRecords;
use App\Http\Controllers\Controller;
use App\Http\Requests\Books\EditionRequest;
use App\Http\Resources\EditionResource;
use App\Models\Book;
use App\Services\Books\BookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BookEditionController extends Controller
{
    use FindsRecords;

    public function __construct(private BookService $books) {}

    public function store(EditionRequest $request, int $book): JsonResponse
    {
        $model = $this->findVisible(Book::class, $book, $request);
        $edition = $this->books->addEdition($request->user(), $model, $request->validated());

        return (new EditionResource($this->withEditor($request, $edition)))->response()->setStatusCode(201);
    }

    public function update(EditionRequest $request, int $book, int $edition): EditionResource
    {
        $model = $this->findVisible(Book::class, $book, $request);
        $current = $model->editions()->findOrFail($edition);
        $updated = $this->books->updateEdition($request->user(), $model, $current, $request->validated());

        return new EditionResource($this->withEditor($request, $updated));
    }

    public function destroy(Request $request, int $book, int $edition): JsonResponse
    {
        $model = $this->findVisible(Book::class, $book, $request);
        $this->books->deleteEdition($request->user(), $model, $model->editions()->findOrFail($edition));

        return response()->json(null, 204);
    }

    private function withEditor(Request $request, $edition)
    {
        return $edition->load(['editor' => fn ($q) => $q->visibleTo($request->user())]);
    }
}
