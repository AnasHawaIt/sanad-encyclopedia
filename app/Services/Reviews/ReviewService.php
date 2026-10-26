<?php

namespace App\Services\Reviews;

use App\Enums\PublicationStatus;
use App\Enums\ReviewAction;
use App\Enums\VerificationStatus;
use App\Models\Asnad;
use App\Models\Book;
use App\Models\Ijaza;
use App\Models\Person;
use App\Models\Review;
use App\Models\User;
use App\Services\Asnad\ChainCounter;
use App\Services\Audit\ActivityLogger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * دورة التوثيق والنشر للسجلات الأربعة (علَم، كتاب، إجازة، سند).
 *
 *   draft | needs_revision | rejected --submit--> in_review
 *   in_review --approve--> verified      in_review --send_back--> needs_revision      in_review --reject--> rejected
 *   verified --send_back--> needs_revision (ويُلغى نشره إن كان منشورًا)
 *   verified + unpublished --publish--> published        published --unpublish--> unpublished
 *
 * الباحث لا يوثّق ولا ينشر بنفسه؛ كل انتقال يُسجَّل في reviews (للإلحاق فقط) وفي سجل التدقيق.
 * من أرسل السجل للمراجعة لا يعتمده ولا يرفضه ولا يعيده بنفسه (إلا إن سُمح صراحة في الإعداد).
 */
class ReviewService
{
    /** @var array<string,class-string<Model>> */
    public const TYPES = ['person' => Person::class, 'book' => Book::class, 'ijaza' => Ijaza::class, 'asnad' => Asnad::class];

    private const NOTES_REQUIRED = [ReviewAction::SendBack, ReviewAction::Reject];

    public function __construct(private ActivityLogger $log, private ChainCounter $counter) {}

    /** @return array{record:Model,review:Review,warnings:list<array{code:string,message:string}>} */
    public function apply(User $actor, Model $record, ReviewAction $action, ?string $notes = null): array
    {
        $notes = $notes !== null && trim($notes) !== '' ? trim($notes) : null;
        if ($notes === null && in_array($action, self::NOTES_REQUIRED, true)) {
            throw ValidationException::withMessages(['notes' => __('reviews.notes_required')]);
        }

        return DB::transaction(function () use ($actor, $record, $action, $notes) {
            /** @var Model $locked */
            $locked = $record::query()->lockForUpdate()->findOrFail($record->getKey());
            if ($locked instanceof Person && $locked->merged_into_person_id !== null) {
                $this->fail('merged');
            }
            $verification = $locked->verification_status;
            $publication = $locked->publication_status;

            $reviews = [];
            switch ($action) {
                case ReviewAction::Submit:
                    $this->requireFrom($verification, [VerificationStatus::Draft, VerificationStatus::NeedsRevision, VerificationStatus::Rejected]);
                    $locked->verification_status = VerificationStatus::InReview;
                    $reviews[] = [$action, $verification->value, VerificationStatus::InReview->value];
                    break;
                case ReviewAction::Approve:
                    $this->requireFrom($verification, [VerificationStatus::InReview]);
                    $this->assertNotSubmitter($actor, $locked);
                    $locked->verification_status = VerificationStatus::Verified;
                    $reviews[] = [$action, $verification->value, VerificationStatus::Verified->value];
                    break;
                case ReviewAction::Reject:
                    $this->requireFrom($verification, [VerificationStatus::InReview]);
                    $this->assertNotSubmitter($actor, $locked);
                    $locked->verification_status = VerificationStatus::Rejected;
                    $reviews[] = [$action, $verification->value, VerificationStatus::Rejected->value];
                    break;
                case ReviewAction::SendBack:
                    $this->requireFrom($verification, [VerificationStatus::InReview, VerificationStatus::Verified]);
                    if ($verification === VerificationStatus::InReview) {
                        $this->assertNotSubmitter($actor, $locked);
                    }
                    $locked->verification_status = VerificationStatus::NeedsRevision;
                    $reviews[] = [$action, $verification->value, VerificationStatus::NeedsRevision->value];
                    if ($publication === PublicationStatus::Published) {
                        // سجل غير موثَّق لا يبقى منشورًا
                        $locked->publication_status = PublicationStatus::Unpublished;
                        $reviews[] = [ReviewAction::Unpublish, PublicationStatus::Published->value, PublicationStatus::Unpublished->value];
                    }
                    break;
                case ReviewAction::Publish:
                    if ($verification !== VerificationStatus::Verified) {
                        $this->fail('publish_needs_verified');
                    }
                    if ($publication === PublicationStatus::Published) {
                        $this->fail('already_published');
                    }
                    $locked->publication_status = PublicationStatus::Published;
                    $reviews[] = [$action, $publication->value, PublicationStatus::Published->value];
                    break;
                case ReviewAction::Unpublish:
                    if ($publication !== PublicationStatus::Published) {
                        $this->fail('not_published');
                    }
                    $locked->publication_status = PublicationStatus::Unpublished;
                    $reviews[] = [$action, $publication->value, PublicationStatus::Unpublished->value];
                    break;
            }

            $locked->timestamps = false; // تغيير الحالة لا يعدّل «آخر تعديل» لمحتوى السجل
            $locked->save();
            $locked->timestamps = true;

            $created = [];
            foreach ($reviews as $i => [$reviewAction, $from, $to]) {
                $created[] = Review::create([
                    'reviewable_type' => $locked->getMorphClass(), 'reviewable_id' => $locked->getKey(),
                    'reviewer_id' => $actor->getKey(), 'action' => $reviewAction->value,
                    'from_status' => $from, 'to_status' => $to, 'notes' => $i === 0 ? $notes : null,
                ]);
                $this->log->record("reviews.{$reviewAction->value}", $locked, ['from' => $from, 'to' => $to], $actor);
            }

            return ['record' => $locked->refresh(), 'review' => $created[0], 'warnings' => $this->warnings($locked, $action)];
        });
    }

    /**
     * السجلات التي تنتظر قرار المراجع، الأقدم إرسالًا أولًا.
     *
     * @return Collection<int,array<string,mixed>>
     */
    public function queue(?string $type = null, int $limit = 200): Collection
    {
        $rows = collect();
        foreach (self::TYPES as $alias => $class) {
            if ($type !== null && $type !== $alias) {
                continue;
            }
            $records = $class::query()->where('verification_status', VerificationStatus::InReview->value)->orderBy('id')->limit($limit)->get();
            if ($records->isEmpty()) {
                continue;
            }
            $submits = Review::query()->with('reviewer:id,name')
                ->where('reviewable_type', (new $class)->getMorphClass())->whereIn('reviewable_id', $records->pluck('id'))
                ->where('action', ReviewAction::Submit->value)->orderBy('id')->get()->groupBy('reviewable_id')->map->last();

            foreach ($records as $record) {
                $submit = $submits->get($record->getKey());
                $rows->push([
                    'type' => $alias, 'id' => $record->getKey(), 'title' => $this->titleOf($record),
                    'submitted_by' => $submit?->reviewer ? ['id' => $submit->reviewer->id, 'name' => $submit->reviewer->name] : null,
                    'submitted_at' => $submit?->created_at?->toIso8601String(),
                    'submit_notes' => $submit?->notes,
                ]);
            }
        }

        return $rows->sortBy([['submitted_at', 'asc'], ['id', 'asc']])->take($limit)->values();
    }

    public function titleOf(Model $record): string
    {
        return match (true) {
            $record instanceof Person => $record->primary_name,
            $record instanceof Book => $record->title,
            $record instanceof Ijaza => $record->ijaza_number ?: "إجازة #{$record->getKey()}",
            default => $record->title ?: "سند #{$record->getKey()}",
        };
    }

    /** @return list<array{code:string,message:string}> تنبيهات لا تمنع القرار لكن المراجع يجب أن يراها */
    private function warnings(Model $record, ReviewAction $action): array
    {
        if (! $record instanceof Asnad || $action !== ReviewAction::Approve) {
            return [];
        }
        $summary = $this->counter->forAsnad($record->load('nodes'));

        return $summary['complete'] ? [] : $summary['issues'];
    }

    private function assertNotSubmitter(User $actor, Model $record): void
    {
        if (config('access.allow_self_approval')) {
            return;
        }
        $submit = Review::query()->where('reviewable_type', $record->getMorphClass())->where('reviewable_id', $record->getKey())
            ->where('action', ReviewAction::Submit->value)->orderByDesc('id')->first();
        if ($submit !== null && (int) $submit->reviewer_id === (int) $actor->getKey()) {
            $this->fail('self_review');
        }
    }

    /** @param list<VerificationStatus> $allowed */
    private function requireFrom(VerificationStatus $current, array $allowed): void
    {
        if (! in_array($current, $allowed, true)) {
            $this->fail('invalid_transition');
        }
    }

    private function fail(string $key): never
    {
        throw ValidationException::withMessages(['action' => __("reviews.{$key}")]);
    }
}
