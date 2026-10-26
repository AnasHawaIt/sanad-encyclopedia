<?php

namespace Tests\Feature\Review;

use App\Models\ActivityLog;
use App\Models\User;
use Tests\Concerns\CreatesRecordFixtures;
use Tests\DatabaseTestCase;

class AuditTest extends DatabaseTestCase
{
    use CreatesRecordFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpRecords();
    }

    private function log(string $event, ?User $user = null, ?string $type = null, ?int $id = null, ?string $at = null): ActivityLog
    {
        $l = ActivityLog::create(['user_id' => $user?->id, 'event' => $event, 'subject_type' => $type, 'subject_id' => $id, 'changes' => ['a' => 1], 'ip_address' => '127.0.0.1']);
        if ($at) {
            $l->forceFill(['created_at' => $at])->save();
        }

        return $l;
    }

    private function events($res): array
    {
        return collect($res->json('data'))->pluck('event')->all();
    }

    public function test_only_reviewers_and_admins_can_read_the_audit(): void
    {
        $this->log('persons.created');
        foreach (['researcher', 'reader'] as $role) {
            $u = $this->userWithRole($role);
            $this->as($u)->getJson('/api/v1/audit')->assertForbidden();
            $this->as($u)->getJson('/api/v1/audit/events')->assertForbidden();
        }
        $this->as($this->reviewer)->getJson('/api/v1/audit')->assertOk();
        $this->as($this->admin())->getJson('/api/v1/audit/events')->assertOk();
        app('auth')->forgetGuards();
        $this->getJson('/api/v1/audit')->assertUnauthorized();
    }

    public function test_filters_by_exact_event_and_by_prefix(): void
    {
        $this->log('persons.created');
        $this->log('persons.updated');
        $this->log('books.created');

        $this->assertSame(['persons.created'], $this->events($this->as($this->reviewer)->getJson('/api/v1/audit?event=persons.created')));
        $this->assertEqualsCanonicalizing(['persons.created', 'persons.updated'], $this->events($this->as($this->reviewer)->getJson('/api/v1/audit?event=persons.')));
        $this->assertSame([], $this->events($this->as($this->reviewer)->getJson('/api/v1/audit?event=nothing')));
    }

    public function test_filters_by_user_subject_and_dates(): void
    {
        $other = $this->userWithRole('researcher');
        $this->log('x.a', $other, 'person', 5, '2026-01-10 10:00:00');
        $this->log('x.b', $this->reviewer, 'book', 6, '2026-02-10 10:00:00');
        $this->log('x.c', $this->reviewer, 'person', 5, '2026-03-10 23:30:00');

        $get = fn (string $q) => $this->events($this->as($this->reviewer)->getJson('/api/v1/audit?'.$q));
        $this->assertEqualsCanonicalizing(['x.b', 'x.c'], $get('user_id='.$this->reviewer->id));
        $this->assertEqualsCanonicalizing(['x.a', 'x.c'], $get('subject_type=person&subject_id=5'));
        $this->assertSame(['x.b'], $get('from=2026-02-01&to=2026-02-28'));
        $this->assertSame(['x.c'], $get('from=2026-03-10&to=2026-03-10'), 'نهاية اليوم المحدد تدخل');
    }

    public function test_newest_first_pagination_and_limits(): void
    {
        foreach (range(1, 5) as $i) {
            $this->log("e.$i");
        }
        $res = $this->as($this->reviewer)->getJson('/api/v1/audit?per_page=2')->assertOk();
        $this->assertSame(['e.5', 'e.4'], $this->events($res));
        $this->assertGreaterThanOrEqual(5, $res->json('meta.total'));
        $this->as($this->reviewer)->getJson('/api/v1/audit?per_page=101')->assertStatus(422)->assertJsonValidationErrors(['per_page']);
        $this->as($this->reviewer)->getJson('/api/v1/audit?per_page=0')->assertStatus(422);
    }

    public function test_validation_errors(): void
    {
        $this->as($this->reviewer)->getJson('/api/v1/audit?from=10-01-2026')->assertStatus(422)->assertJsonValidationErrors(['from']);
        $this->as($this->reviewer)->getJson('/api/v1/audit?from=2026-03-01&to=2026-02-01')->assertStatus(422)->assertJsonValidationErrors(['to']);
        $this->as($this->reviewer)->getJson('/api/v1/audit?user_id=abc')->assertStatus(422)->assertJsonValidationErrors(['user_id']);
    }

    public function test_event_list_is_distinct_and_sorted(): void
    {
        $this->log('b.x');
        $this->log('a.x');
        $this->log('b.x');
        $events = $this->as($this->reviewer)->getJson('/api/v1/audit/events')->assertOk()->json('data');
        $this->assertSame($events, array_values(array_unique($events)));
        $sorted = $events;
        sort($sorted);
        $this->assertSame($sorted, $events);
        $this->assertContains('a.x', $events);
    }

    public function test_the_audit_is_read_only(): void
    {
        $l = $this->log('x.y');
        $admin = $this->admin();
        $this->as($admin)->postJson('/api/v1/audit', [])->assertStatus(405);
        $this->as($admin)->deleteJson("/api/v1/audit/{$l->id}")->assertStatus(404);
        $this->as($admin)->putJson("/api/v1/audit/{$l->id}", [])->assertStatus(404);
        $this->assertDatabaseHas('activity_logs', ['id' => $l->id]);
    }
}
