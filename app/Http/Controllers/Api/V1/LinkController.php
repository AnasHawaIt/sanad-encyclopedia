<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\FindsRecords;
use App\Http\Controllers\Controller;
use App\Http\Requests\Links\SyncBookAsnadsRequest;
use App\Http\Requests\Links\SyncIjazaAsnadsRequest;
use App\Http\Requests\Links\SyncIjazaBooksRequest;
use App\Models\Book;
use App\Models\Ijaza;
use App\Services\Asnad\LinkService;
use Illuminate\Http\JsonResponse;

/**
 * ربط الكتب والأسانيد والإجازات: كل مسار يستبدل قائمة الروابط من جهة المالك (الكتاب أو الإجازة).
 * لا يُلزَم السند عند ربط إجازة بكتاب (قرار ق2): تظهر تحذيرات علمية في الإجازة لا أخطاء.
 */
class LinkController extends Controller
{
    use FindsRecords;

    public function __construct(private LinkService $links) {}

    public function bookAsnads(SyncBookAsnadsRequest $request, int $book): JsonResponse
    {
        $model = $this->findVisible(Book::class, $book, $request);
        $this->links->syncBookAsnads($request->user(), $model, $request->validated()['items']);

        return $this->linked($model->asnads()->visibleTo($request->user())->orderBy('asnads.id')->get(['asnads.id', 'asnads.title']));
    }

    public function ijazaAsnads(SyncIjazaAsnadsRequest $request, int $ijaza): JsonResponse
    {
        $model = $this->findVisible(Ijaza::class, $ijaza, $request);
        $this->links->syncIjazaAsnads($request->user(), $model, $request->validated()['items']);

        return $this->linked($model->asnads()->visibleTo($request->user())->orderBy('asnads.id')->get(['asnads.id', 'asnads.title']));
    }

    public function ijazaBooks(SyncIjazaBooksRequest $request, int $ijaza): JsonResponse
    {
        $model = $this->findVisible(Ijaza::class, $ijaza, $request);
        $this->links->syncIjazaBooks($request->user(), $model, $request->validated()['items']);

        return $this->linked($model->books()->visibleTo($request->user())->orderBy('books.id')->get(['books.id', 'books.title']));
    }

    private function linked($rows): JsonResponse
    {
        return response()->json(['data' => $rows->map(fn ($r) => [
            'id' => $r->id,
            'title' => $r->title,
            'book_edition_id' => $r->pivot->book_edition_id ?? null,
        ])->values()]);
    }
}
