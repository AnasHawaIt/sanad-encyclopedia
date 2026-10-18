<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\FindsRecords;
use App\Http\Controllers\Controller;
use App\Http\Requests\Asnads\SplitRequest;
use App\Models\Asnad;
use App\Services\Asnad\ChainCounter;
use App\Services\Asnad\ChainTextSplitter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** عمليات قراءة وتحليل لا تكتب شيئًا: الأعداد، وأعداد مجموعة أسانيد، واقتراح تقسيم نص. */
class AsnadAnalysisController extends Controller
{
    use FindsRecords;

    public function __construct(private ChainCounter $counter, private ChainTextSplitter $splitter) {}

    public function counts(Request $request, int $asnad): JsonResponse
    {
        $model = $this->findVisible(Asnad::class, $asnad, $request);
        $model->load('nodes');
        $visibleBooks = $model->books()->visibleTo($request->user())->get();

        return response()->json(['data' => $this->counter->forAsnad($model) + [
            'definitions' => $this->counter->definitions(),
            'authors' => $this->counter->authorCheck($model, $visibleBooks),
        ]]);
    }

    /** الأعلام الفريدون وعدد الظهور عبر مجموعة أسانيد (مثل أسانيد كتاب أو نتائج بحث). */
    public function summary(Request $request): JsonResponse
    {
        $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['integer', 'distinct'],
        ]);

        $asnads = Asnad::query()->visibleTo($request->user())->whereKey($request->input('ids'))->with('nodes')->orderBy('id')->get();

        return response()->json(['data' => $this->counter->forSet($asnads)]);
    }

    /** اقتراح فقط: لا يُحفظ شيء ولا يُربط عنصر بعلَم. */
    public function suggestSplit(SplitRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->splitter->suggest($request->validated()['text'], $request->user())]);
    }
}
