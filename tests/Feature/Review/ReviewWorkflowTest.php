<?php

namespace Tests\Feature\Review;

use App\Models\ActivityLog;
use App\Models\Asnad;
use App\Models\AsnadNode;
use App\Models\Book;
use App\Models\Ijaza;
use App\Models\Person;
use App\Models\Review;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Tests\Concerns\CreatesRecordFixtures;
use Tests\DatabaseTestCase;

class ReviewWorkflowTest extends DatabaseTestCase
{
    use CreatesRecordFixtures;

    private User $publisher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpRecords();
        $this->publisher = $this->userWithRole('publisher');
    }

    /** @return array<string,array{0:string,1:Model}> */
    private function records(): array
    {
        return [
            'persons' => ['persons', Person::factory()->create()],
            'books' => ['books', Book::factory()->create()],
            'ijazas' => ['ijazas', Ijaza::factory()->create()],
            'asnads' => ['asnads', Asnad::factory()->create()],
        ];
    }

    private function act(User $user, string $prefix, int $id, string $path, array $body = [])
    {
        return $this->as($user)->postJson("/api/v1/{$prefix}/{$id}/{$path}", $body);
    }

    private function state($record): array
    {
        $record->refresh();

        return [$record->verification_status->value, $record->publication_status->value];
    }

    public function test_every_record_type_goes_through_submit_approve_publish_and_becomes_visible_to_readers(): void
    {
        foreach ($this->records() as [$prefix, $record]) {
            $this->as($this->reader)->getJson("/api/v1/{$prefix}/{$record->id}")->assertNotFound();

            $this->act($this->researcher, $prefix, $record->id, 'submit')->assertOk()
                ->assertJsonPath('data.verification_status.value', 'in_review')->assertJsonPath('data.review.action.value', 'submit');
            $this->act($this->reviewer, $prefix, $record->id, 'approve')->assertOk()->assertJsonPath('data.verification_status.value', 'verified');
            $this->as($this->reader)->getJson("/api/v1/{$prefix}/{$record->id}")->assertNotFound('موثَّق لكن غير منشور');
            $this->act($this->publisher, $prefix, $record->id, 'publish')->assertOk()->assertJsonPath('data.publication_status.value', 'published');

            $this->as($this->reader)->getJson("/api/v1/{$prefix}/{$record->id}")->assertOk();
            $this->assertSame(['verified', 'published'], $this->state($record), $prefix);
            $this->assertSame(['submit', 'approve', 'publish'], Review::where('reviewable_id', $record->id)->where('reviewable_type', $record->getMorphClass())->orderBy('id')->pluck('action')->map->value->all());
        }
    }

    public function test_each_step_needs_its_own_permission(): void
    {
        $person = Person::factory()->create();

        $this->act($this->reviewer, 'persons', $person->id, 'submit')->assertForbidden();
        $this->act($this->reader, 'persons', $person->id, 'submit')->assertForbidden();
        $this->act($this->researcher, 'persons', $person->id, 'submit')->assertOk();
        foreach (['approve', 'reject', 'send-back'] as $path) {
            $this->act($this->researcher, 'persons', $person->id, $path, ['notes' => 'سبب كافٍ'])->assertForbidden();
            $this->act($this->publisher, 'persons', $person->id, $path, ['notes' => 'سبب كافٍ'])->assertForbidden();
        }
        foreach (['publish', 'unpublish'] as $path) {
            $this->act($this->researcher, 'persons', $person->id, $path)->assertForbidden();
            $this->act($this->reviewer, 'persons', $person->id, $path)->assertForbidden();
        }
        $this->assertSame(['in_review', 'unpublished'], $this->state($person));

        app('auth')->forgetGuards();
        $this->postJson("/api/v1/persons/{$person->id}/approve")->assertStatus(401);
    }

    public function test_invalid_transitions_are_rejected_and_change_nothing(): void
    {
        $draft = Person::factory()->create();
        $inReview = Person::factory()->create(['verification_status' => 'in_review']);
        $verified = Person::factory()->create(['verification_status' => 'verified']);
        $published = Person::factory()->published()->create();

        $this->act($this->reviewer, 'persons', $draft->id, 'approve')->assertStatus(422)->assertJsonValidationErrors(['action']);
        $this->act($this->reviewer, 'persons', $draft->id, 'reject', ['notes' => 'سبب كافٍ'])->assertStatus(422);
        $this->act($this->reviewer, 'persons', $draft->id, 'send-back', ['notes' => 'سبب كافٍ'])->assertStatus(422);
        $this->act($this->publisher, 'persons', $draft->id, 'publish')->assertStatus(422);
        $this->act($this->publisher, 'persons', $inReview->id, 'publish')->assertStatus(422);
        $this->act($this->researcher, 'persons', $inReview->id, 'submit')->assertStatus(422);
        $this->act($this->researcher, 'persons', $verified->id, 'submit')->assertStatus(422);
        $this->act($this->reviewer, 'persons', $verified->id, 'approve')->assertStatus(422);
        $this->act($this->publisher, 'persons', $draft->id, 'unpublish')->assertStatus(422);
        $this->act($this->publisher, 'persons', $published->id, 'publish')->assertStatus(422);

        $this->assertSame(['draft', 'unpublished'], $this->state($draft));
        $this->assertSame(['in_review', 'unpublished'], $this->state($inReview));
        $this->assertSame(0, Review::count());
        $this->assertSame(0, ActivityLog::where('event', 'like', 'reviews.%')->count());
    }

    public function test_reject_and_send_back_need_a_reason_which_is_stored(): void
    {
        $person = Person::factory()->create(['verification_status' => 'in_review']);

        $this->act($this->reviewer, 'persons', $person->id, 'reject')->assertStatus(422)->assertJsonValidationErrors(['notes']);
        $this->act($this->reviewer, 'persons', $person->id, 'send-back', ['notes' => '   '])->assertStatus(422);
        $this->assertSame(['in_review', 'unpublished'], $this->state($person));

        $this->act($this->reviewer, 'persons', $person->id, 'send-back', ['notes' => 'يلزم مرجع للوفاة'])->assertOk()->assertJsonPath('data.verification_status.value', 'needs_revision');
        $review = Review::firstOrFail();
        $this->assertSame('يلزم مرجع للوفاة', $review->notes);
        $this->assertSame(['in_review', 'needs_revision'], [$review->from_status, $review->to_status]);
        $this->assertSame($this->reviewer->id, $review->reviewer_id);
    }

    public function test_rejected_and_sent_back_records_can_be_resubmitted(): void
    {
        $person = Person::factory()->create(['verification_status' => 'in_review']);
        $this->act($this->reviewer, 'persons', $person->id, 'reject', ['notes' => 'غير مكتمل'])->assertOk();
        $this->act($this->researcher, 'persons', $person->id, 'submit')->assertOk();
        $this->act($this->reviewer, 'persons', $person->id, 'send-back', ['notes' => 'عدّل الاسم'])->assertOk();
        $this->act($this->researcher, 'persons', $person->id, 'submit')->assertOk();

        $this->assertSame(['in_review', 'unpublished'], $this->state($person));
        $this->assertSame(4, Review::count());
    }

    public function test_sending_back_a_published_record_unpublishes_it_and_hides_it_from_readers(): void
    {
        $person = Person::factory()->published()->create();
        $this->as($this->reader)->getJson("/api/v1/persons/{$person->id}")->assertOk();

        $this->act($this->reviewer, 'persons', $person->id, 'send-back', ['notes' => 'ظهر خطأ في التاريخ'])->assertOk()
            ->assertJsonPath('data.verification_status.value', 'needs_revision')->assertJsonPath('data.publication_status.value', 'unpublished');

        $this->as($this->reader)->getJson("/api/v1/persons/{$person->id}")->assertNotFound();
        $this->assertSame(['send_back', 'unpublish'], Review::orderBy('id')->pluck('action')->map->value->all());
    }

    public function test_unpublish_keeps_verification(): void
    {
        $person = Person::factory()->published()->create();

        $this->act($this->publisher, 'persons', $person->id, 'unpublish')->assertOk();

        $this->assertSame(['verified', 'unpublished'], $this->state($person));
        $this->as($this->reader)->getJson("/api/v1/persons/{$person->id}")->assertNotFound();
    }

    public function test_submitter_cannot_decide_on_their_own_submission_unless_configured(): void
    {
        $admin = $this->admin();
        $person = Person::factory()->create();

        $this->act($admin, 'persons', $person->id, 'submit')->assertOk();
        foreach ([['approve', []], ['reject', ['notes' => 'سبب']], ['send-back', ['notes' => 'سبب']]] as [$path, $body]) {
            $this->act($admin, 'persons', $person->id, $path, $body)->assertStatus(422)->assertJsonValidationErrors(['action']);
        }
        $this->assertSame(['in_review', 'unpublished'], $this->state($person));

        $this->act($this->reviewer, 'persons', $person->id, 'approve')->assertOk();

        $other = Person::factory()->create();
        $this->act($admin, 'persons', $other->id, 'submit')->assertOk();
        config(['access.allow_self_approval' => true]);
        $this->act($admin, 'persons', $other->id, 'approve')->assertOk();
    }

    public function test_status_change_does_not_touch_the_records_updated_at_or_content(): void
    {
        $person = Person::factory()->create(['primary_name' => 'اسم ثابت']);
        $person->forceFill(['updated_at' => now()->subYear()])->saveQuietly();
        $before = $person->fresh()->updated_at->toIso8601String();

        $this->act($this->researcher, 'persons', $person->id, 'submit')->assertOk();

        $this->assertSame($before, $person->fresh()->updated_at->toIso8601String());
        $this->assertSame('اسم ثابت', $person->fresh()->primary_name);
    }

    public function test_merged_person_cannot_enter_review(): void
    {
        $merged = Person::factory()->create(['merged_into_person_id' => Person::factory()->create()->id]);

        $this->act($this->researcher, 'persons', $merged->id, 'submit')->assertStatus(422)->assertJsonValidationErrors(['action']);
    }

    public function test_approving_an_asnad_with_unresolved_nodes_succeeds_but_warns(): void
    {
        $asnad = Asnad::factory()->create(['verification_status' => 'in_review']);
        AsnadNode::create(['asnad_id' => $asnad->id, 'sequence_number' => 1, 'unresolved_name' => 'مجهول']);

        $res = $this->act($this->reviewer, 'asnads', $asnad->id, 'approve')->assertOk();

        $this->assertSame('unresolved_nodes', $res->json('data.warnings.0.code'));
        $this->assertSame('verified', $asnad->fresh()->verification_status->value);

        $clean = Asnad::factory()->create(['verification_status' => 'in_review']);
        AsnadNode::create(['asnad_id' => $clean->id, 'sequence_number' => 1, 'person_id' => Person::factory()->create()->id]);
        $this->assertSame([], $this->act($this->reviewer, 'asnads', $clean->id, 'approve')->json('data.warnings'));
    }

    public function test_history_lists_reviews_newest_first_for_the_team_only(): void
    {
        $person = Person::factory()->create();
        $this->act($this->researcher, 'persons', $person->id, 'submit')->assertOk();
        $this->act($this->reviewer, 'persons', $person->id, 'send-back', ['notes' => 'ملاحظة'])->assertOk();

        $rows = $this->as($this->researcher)->getJson("/api/v1/persons/{$person->id}/reviews")->assertOk()->json('data');
        $this->assertSame(['send_back', 'submit'], array_map(fn ($r) => $r['action']['value'], $rows));
        $this->assertSame($this->reviewer->id, $rows[0]['reviewer']['id']);
        $this->assertSame('ملاحظة', $rows[0]['notes']);

        $this->as($this->reader)->getJson("/api/v1/persons/{$person->id}/reviews")->assertForbidden();
        $this->as($this->researcher)->getJson('/api/v1/persons/999999/reviews')->assertNotFound();
    }

    public function test_review_log_is_append_only_there_is_no_route_to_change_it(): void
    {
        $this->as($this->admin())->deleteJson('/api/v1/persons/1/reviews')->assertStatus(405);
        $this->as($this->admin())->putJson('/api/v1/persons/1/reviews', [])->assertStatus(405);
    }

    public function test_queue_lists_waiting_records_of_every_type_oldest_first_with_the_submitter(): void
    {
        foreach ($this->records() as [$prefix, $record]) {
            $this->act($this->researcher, $prefix, $record->id, 'submit', ['notes' => "جاهز {$prefix}"])->assertOk();
        }
        Person::factory()->create(); // مسودة: لا تظهر

        $rows = $this->as($this->reviewer)->getJson('/api/v1/reviews/queue')->assertOk()->json('data');

        $this->assertCount(4, $rows);
        $this->assertEqualsCanonicalizing(['person', 'book', 'ijaza', 'asnad'], array_column($rows, 'type'));
        $this->assertSame($this->researcher->id, $rows[0]['submitted_by']['id']);
        $this->assertNotEmpty($rows[0]['title']);
        $this->assertStringStartsWith('جاهز', $rows[0]['submit_notes']);

        $this->assertCount(1, $this->as($this->reviewer)->getJson('/api/v1/reviews/queue?type=book')->json('data'));
        $this->as($this->reviewer)->getJson('/api/v1/reviews/queue?type=nonsense')->assertStatus(422);
        $this->as($this->researcher)->getJson('/api/v1/reviews/queue')->assertForbidden();
        $this->as($this->reader)->getJson('/api/v1/reviews/queue')->assertForbidden();
    }

    public function test_decided_records_leave_the_queue(): void
    {
        $person = Person::factory()->create();
        $this->act($this->researcher, 'persons', $person->id, 'submit')->assertOk();
        $this->act($this->reviewer, 'persons', $person->id, 'approve')->assertOk();

        $this->assertSame([], $this->as($this->reviewer)->getJson('/api/v1/reviews/queue')->json('data'));
    }

    public function test_every_transition_is_audited_with_the_actor_and_statuses(): void
    {
        $person = Person::factory()->create();
        $this->act($this->researcher, 'persons', $person->id, 'submit');
        $this->act($this->reviewer, 'persons', $person->id, 'approve');
        $this->act($this->publisher, 'persons', $person->id, 'publish');

        $logs = ActivityLog::where('subject_id', $person->id)->where('event', 'like', 'reviews.%')->orderBy('id')->get();
        $this->assertSame(['reviews.submit', 'reviews.approve', 'reviews.publish'], $logs->pluck('event')->all());
        $this->assertSame([$this->researcher->id, $this->reviewer->id, $this->publisher->id], $logs->pluck('user_id')->all());
        $this->assertEquals(['from' => 'in_review', 'to' => 'verified'], $logs[1]->changes);
    }

    public function test_unknown_record_is_404_and_status_fields_still_cannot_be_forged_through_updates(): void
    {
        $this->act($this->researcher, 'persons', 999999, 'submit')->assertNotFound();
        $this->as($this->researcher)->getJson('/api/v1/widgets/1/reviews')->assertNotFound();

        $person = Person::factory()->create();
        $this->as($this->researcher)->putJson("/api/v1/persons/{$person->id}", ['primary_name' => 'تعديل', 'verification_status' => 'verified', 'publication_status' => 'published'])->assertOk();
        $this->assertSame(['draft', 'unpublished'], $this->state($person));
    }
}
