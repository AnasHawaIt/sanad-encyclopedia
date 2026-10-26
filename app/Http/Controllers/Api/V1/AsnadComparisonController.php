<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\FindsRecords;
use App\Http\Controllers\Controller;
use App\Models\Asnad;
use App\Models\Book;
use App\Services\Asnad\AsnadComparator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/** مقارنة الأسانيد وشجرتها: قراءة فقط، وبقيد رؤية المستخدم. */
class AsnadComparisonController extends Controller
{
    use FindsRecords;

    public const COMPARE_LIMIT = 20;

    public const TREE_LIMIT = 100;

    public function __construct(private AsnadComparator $comparator) {}

    public function compare(Request $request): JsonResponse
    {
        $asnads = $this->selected($request, 2, self::COMPARE_LIMIT);

        return response()->json(['data' => $this->comparator->compare($asnads, $request->user(), $this->scope($request))]);
    }

    public function tree(Request $request): JsonResponse
    {
        $asnads = $this->selected($request, 1, self::TREE_LIMIT);

        return response()->json(['data' => $this->comparator->tree($asnads, $request->user(), $this->scope($request))]);
    }

    public function bookCompare(Request $request, int $book): JsonResponse
    {
        [$asnads, $meta] = $this->ofBook($request, $book, self::COMPARE_LIMIT);

        return response()->json(['data' => $this->comparator->compare($asnads, $request->user(), $this->scope($request)) + $meta]);
    }

    public function bookTree(Request $request, int $book): JsonResponse
    {
        [$asnads, $meta] = $this->ofBook($request, $book, self::TREE_LIMIT);

        return response()->json(['data' => $this->comparator->tree($asnads, $request->user(), $this->scope($request)) + $meta]);
    }

    private function scope(Request $request): string
    {
        $request->validate(['scope' => ['sometimes', 'in:counted,full']]);

        return $request->input('scope', AsnadComparator::SCOPE_COUNTED);
    }

    /** السندات المرئية فقط؛ غير المرئي يُسقَط بصمت كي لا يُكشف وجوده، ويُشترط ما يبقى منها الحدّ الأدنى. */
    private function selected(Request $request, int $min, int $max)
    {
        $request->validate([
            'ids' => ['required', 'array', "min:{$min}", "max:{$max}"],
            'ids.*' => ['integer', 'distinct'],
        ]);

        $asnads = Asnad::query()->visibleTo($request->user())->whereKey($request->input('ids'))->with('nodes')->orderBy('id')->get();
        if ($asnads->count() < $min) {
            throw ValidationException::withMessages(['ids' => __('asnad.compare.too_few', ['min' => $min])]);
        }

        return $asnads;
    }

    /** @return array{0:Collection,1:array<string,mixed>} */
    private function ofBook(Request $request, int $book, int $limit): array
    {
        $model = $this->findVisible(Book::class, $book, $request);
        $query = $model->asnads()->visibleTo($request->user());
        $total = (clone $query)->count();
        $asnads = $query->with('nodes')->orderBy('asnads.id')->limit($limit)->get();

        return [$asnads, ['book_id' => $model->id, 'book_asnads_total' => $total, 'truncated' => $total > $limit, 'limit' => $limit]];
    }
}
