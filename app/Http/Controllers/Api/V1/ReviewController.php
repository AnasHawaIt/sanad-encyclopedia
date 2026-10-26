<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ReviewAction;
use App\Http\Controllers\Api\V1\Concerns\FindsRecords;
use App\Http\Controllers\Controller;
use App\Models\Review;
use App\Services\Reviews\ReviewService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** انتقالات المراجعة والنشر وسجل المراجعات وطابور المراجع. صلاحية كل انتقال تُفرض في المسار. */
class ReviewController extends Controller
{
    use FindsRecords;

    public function __construct(private ReviewService $reviews) {}

    public function transition(Request $request, int $entity): JsonResponse
    {
        $request->validate(['notes' => ['sometimes', 'nullable', 'string', 'max:5000']]);
        $class = $this->classFor($request);
        $action = ReviewAction::from($request->route('action'));

        $record = $this->findVisible($class, $entity, $request);
        $result = $this->reviews->apply($request->user(), $record, $action, $request->input('notes'));
        $review = $result['review']->refresh();

        return response()->json(['data' => [
            'type' => $request->route('type'),
            'id' => $result['record']->getKey(),
            'verification_status' => $this->status($result['record']->verification_status),
            'publication_status' => $this->status($result['record']->publication_status),
            'review' => $this->present($review),
            'warnings' => $result['warnings'],
        ]]);
    }

    public function history(Request $request, int $entity): JsonResponse
    {
        $record = $this->findVisible($this->classFor($request), $entity, $request);
        $rows = Review::query()->with('reviewer:id,name')
            ->where('reviewable_type', $record->getMorphClass())->where('reviewable_id', $record->getKey())
            ->orderByDesc('id')->get();

        return response()->json(['data' => $rows->map(fn (Review $r) => $this->present($r))->values()]);
    }

    public function queue(Request $request): JsonResponse
    {
        $request->validate(['type' => ['sometimes', 'nullable', 'in:'.implode(',', array_keys(ReviewService::TYPES))]]);

        return response()->json(['data' => $this->reviews->queue($request->input('type') ?: null)->all()]);
    }

    /** @return class-string<Model> */
    private function classFor(Request $request): string
    {
        $type = (string) $request->route('type');
        if (! isset(ReviewService::TYPES[$type])) {
            throw ValidationException::withMessages(['type' => __('reviews.unknown_type')]);
        }

        return ReviewService::TYPES[$type];
    }

    private function status(\BackedEnum $enum): array
    {
        return ['value' => $enum->value, 'label' => $enum->label()];
    }

    private function present(Review $review): array
    {
        return [
            'id' => $review->id,
            'action' => $this->status($review->action),
            'from_status' => $review->from_status,
            'to_status' => $review->to_status,
            'notes' => $review->notes,
            'reviewer' => $review->relationLoaded('reviewer') && $review->reviewer ? ['id' => $review->reviewer->id, 'name' => $review->reviewer->name] : null,
            'created_at' => $review->created_at?->toIso8601String(),
        ];
    }
}
