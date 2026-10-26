<?php

namespace Tests\Feature\Review;

use App\Models\ActivityLog;
use App\Models\Asnad;
use App\Models\AsnadNode;
use App\Models\Book;
use App\Models\BookContributor;
use App\Models\Ijaza;
use App\Models\Person;
use App\Models\PersonDateClaim;
use App\Models\PersonMergeRepoint;
use App\Models\PersonMergeRequest;
use App\Models\PersonNameVariant;
use App\Models\PersonRelation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\CreatesRecordFixtures;
use Tests\DatabaseTestCase;

class PersonMergeTest extends DatabaseTestCase
{
    use CreatesRecordFixtures;

    private Person $primary;

    private Person $secondary;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpRecords();
        $this->primary = Person::factory()->published()->create(['primary_name' => 'محمد بن إسماعيل', 'full_name' => 'محمد بن إسماعيل البخاري', 'death_year_h' => 256, 'name_search' => 'محمد بن اسماعيل']);
        $this->secondary = Person::factory()->create(['primary_name' => 'البخاري', 'kunyah' => 'أبو عبد الله', 'death_year_h' => 257, 'name_search' => 'البخاري']);
    }

    private function request(?User $by = null, ?Person $primary = null, ?Person $secondary = null): PersonMergeRequest
    {
        return PersonMergeRequest::create([
            'primary_person_id' => ($primary ?? $this->primary)->id, 'secondary_person_id' => ($secondary ?? $this->secondary)->id,
            'reason' => 'الشخص نفسه', 'requested_by' => ($by ?? $this->researcher)->id,
        ]);
    }

    private function approve(PersonMergeRequest $r, ?User $by = null)
    {
        return $this->as($by ?? $this->reviewer)->postJson("/api/v1/merge-requests/{$r->id}/approve");
    }

    private function usage(): void
    {
        $this->book = Book::factory()->create(['author_person_id' => $this->secondary->id]);
        $this->asnad = Asnad::factory()->create();
        $this->node = AsnadNode::create(['asnad_id' => $this->asnad->id, 'sequence_number' => 1, 'person_id' => $this->secondary->id]);
        $this->ijaza = Ijaza::factory()->create(['grantor_person_id' => $this->secondary->id, 'recipient_person_id' => Person::factory()->create()->id]);
    }

    public Book $book;

    public Asnad $asnad;

    public AsnadNode $node;

    public Ijaza $ijaza;

    // ------------------------------------------------------------- الطلب

    public function test_researcher_requests_a_merge_which_changes_nothing_by_itself(): void
    {
        $this->usage();

        $res = $this->as($this->researcher)->postJson('/api/v1/merge-requests', [
            'primary_person_id' => $this->primary->id, 'secondary_person_id' => $this->secondary->id, 'reason' => 'هما شخص واحد بدلالة النسبة',
        ])->assertCreated()->assertJsonPath('data.status.value', 'pending')->assertJsonPath('data.requested_by.id', $this->researcher->id);

        $this->assertNull($this->secondary->fresh()->merged_into_person_id);
        $this->assertSame($this->secondary->id, $this->node->fresh()->person_id);
        $this->assertSame(0, PersonMergeRepoint::count());
        $this->assertDatabaseHas('activity_logs', ['event' => 'merges.requested', 'subject_id' => $res->json('data.id')]);
    }

    public function test_request_validation(): void
    {
        $url = '/api/v1/merge-requests';
        $ok = ['primary_person_id' => $this->primary->id, 'secondary_person_id' => $this->secondary->id, 'reason' => 'سبب مفهوم'];
        $merged = Person::factory()->create(['merged_into_person_id' => $this->primary->id]);
        $trashed = Person::factory()->create();
        $trashed->delete();

        $this->as($this->researcher)->postJson($url, [])->assertStatus(422);
        $this->as($this->researcher)->postJson($url, ['secondary_person_id' => $this->primary->id] + $ok)->assertStatus(422)->assertJsonValidationErrors(['secondary_person_id']);
        $this->as($this->researcher)->postJson($url, ['reason' => 'قصير'] + $ok)->assertStatus(422)->assertJsonValidationErrors(['reason']);
        $this->as($this->researcher)->postJson($url, ['secondary_person_id' => 999999] + $ok)->assertStatus(422);
        $this->as($this->researcher)->postJson($url, ['secondary_person_id' => $merged->id] + $ok)->assertStatus(422);
        $this->as($this->researcher)->postJson($url, ['secondary_person_id' => $trashed->id] + $ok)->assertStatus(422);
        $this->assertSame(0, PersonMergeRequest::count());

        $this->as($this->researcher)->postJson($url, $ok)->assertCreated();
        $this->as($this->researcher)->postJson($url, $ok)->assertStatus(422)->assertJsonValidationErrors(['primary_person_id']);
        $this->as($this->researcher)->postJson($url, ['primary_person_id' => $this->secondary->id, 'secondary_person_id' => $this->primary->id, 'reason' => 'سبب مفهوم'])->assertStatus(422);
        $this->assertSame(1, PersonMergeRequest::count());
    }

    public function test_permissions_for_each_step(): void
    {
        $r = $this->request();
        $payload = ['primary_person_id' => $this->primary->id, 'secondary_person_id' => $this->secondary->id, 'reason' => 'سبب مفهوم'];

        $this->as($this->reader)->postJson('/api/v1/merge-requests', $payload)->assertForbidden();
        $this->as($this->reviewer)->postJson('/api/v1/merge-requests', $payload)->assertForbidden();
        $this->as($this->reader)->getJson('/api/v1/merge-requests')->assertForbidden();
        $this->as($this->researcher)->postJson("/api/v1/merge-requests/{$r->id}/approve")->assertForbidden();
        $this->as($this->researcher)->postJson("/api/v1/merge-requests/{$r->id}/reject", ['notes' => 'x'])->assertForbidden();
        $this->as($this->reviewer)->postJson("/api/v1/merge-requests/{$r->id}/revert", ['notes' => 'x'])->assertForbidden();
        $this->as($this->researcher)->getJson('/api/v1/merge-requests')->assertOk();
        $this->as($this->reviewer)->getJson('/api/v1/merge-requests')->assertOk();

        app('auth')->forgetGuards();
        $this->postJson("/api/v1/merge-requests/{$r->id}/approve")->assertStatus(401);
    }

    // ------------------------------------------------------------- المعاينة

    public function test_preview_shows_what_would_move_without_writing_anything(): void
    {
        $this->usage();
        BookContributor::create(['book_id' => $this->book->id, 'person_id' => $this->secondary->id, 'role' => 'editor']);
        $r = $this->request();
        $before = [PersonMergeRepoint::count(), PersonNameVariant::count(), $this->node->fresh()->person_id];

        $res = $this->as($this->reviewer)->getJson("/api/v1/merge-requests/{$r->id}")->assertOk();
        $preview = collect($res->json('data.preview.repoint'))->mapWithKeys(fn ($o) => ["{$o['table']}.{$o['column']}" => $o['count']]);

        $this->assertSame(1, $preview['books.author_person_id']);
        $this->assertSame(1, $preview['asnad_nodes.person_id']);
        $this->assertSame(1, $preview['ijazas.grantor_person_id']);
        $this->assertSame(1, $preview['book_contributors.person_id']);
        $this->assertContains('البخاري', $res->json('data.preview.name_variants_to_add'));
        $this->assertContains('أبو عبد الله', $res->json('data.preview.name_variants_to_add'));
        $this->assertContains('death_year_h', array_column($res->json('data.preview.differences'), 'field'), 'تعارض سنة الوفاة يُعرض للمراجع');
        $this->assertTrue($res->json('data.preview.primary_unchanged'));
        $this->assertSame($before, [PersonMergeRepoint::count(), PersonNameVariant::count(), $this->node->fresh()->person_id]);
    }

    // ------------------------------------------------------------- التنفيذ

    public function test_approval_moves_references_hides_the_merged_person_and_keeps_the_primary_untouched(): void
    {
        $this->usage();
        $r = $this->request();

        $this->approve($r)->assertOk()->assertJsonPath('data.status.value', 'executed')->assertJsonPath('data.decided_by.id', $this->reviewer->id);

        $this->assertSame($this->primary->id, $this->node->fresh()->person_id);
        $this->assertSame($this->primary->id, $this->book->fresh()->author_person_id);
        $this->assertSame($this->primary->id, $this->ijaza->fresh()->grantor_person_id);
        $this->assertSame($this->primary->id, $this->secondary->fresh()->merged_into_person_id);
        $this->assertNotNull($this->secondary->fresh()->primary_name, 'العلَم المدموج يبقى بكل بياناته');
        $this->assertSame(['محمد بن إسماعيل', 'محمد بن إسماعيل البخاري', 256], [$this->primary->fresh()->primary_name, $this->primary->fresh()->full_name, $this->primary->fresh()->death_year_h]);
        $this->assertNotContains($this->secondary->id, $this->ids($this->as($this->reviewer)->getJson('/api/v1/persons?per_page=100')));
        $this->assertEqualsCanonicalizing(['البخاري', 'أبو عبد الله', $this->secondary->full_name], PersonNameVariant::where('person_id', $this->primary->id)->pluck('name')->all());
        $this->assertSame(3, PersonMergeRepoint::where('merge_request_id', $r->id)->count());
        $this->assertDatabaseHas('activity_logs', ['event' => 'merges.executed', 'user_id' => $this->reviewer->id]);

        $res = $this->as($this->reviewer)->getJson("/api/v1/merge-requests/{$r->id}")->json('data');
        $this->assertSame(1, $res['result']['counts']['asnad_nodes.person_id']);
        $this->assertNull($res['preview'] ?? null);
    }

    public function test_merged_person_is_found_through_the_primary_search_by_its_old_name(): void
    {
        $this->usage();
        $this->approve($this->request())->assertOk();

        $found = $this->ids($this->as($this->reviewer)->getJson('/api/v1/persons?q='.urlencode('البخاري')));

        $this->assertContains($this->primary->id, $found, 'اسم المدموج صار صيغة بديلة للأساسي');
        $this->assertNotContains($this->secondary->id, $found);
    }

    public function test_counts_after_a_merge_treat_both_records_as_one_person(): void
    {
        $asnad = Asnad::factory()->create();
        AsnadNode::create(['asnad_id' => $asnad->id, 'sequence_number' => 1, 'person_id' => $this->primary->id]);
        AsnadNode::create(['asnad_id' => $asnad->id, 'sequence_number' => 2, 'person_id' => $this->secondary->id]);
        $before = $this->as($this->reviewer)->getJson("/api/v1/asnads/{$asnad->id}")->json('data.counts');
        $this->assertSame(2, $before['unique_persons']);

        $this->approve($this->request())->assertOk();

        $after = $this->as($this->reviewer)->getJson("/api/v1/asnads/{$asnad->id}")->json('data.counts');
        $this->assertSame(2, $after['persons_in_chain'], 'عدد الرجال (المواضع) لا يتغير');
        $this->assertSame(1, $after['unique_persons'], 'لكن الأعلام المختلفين صاروا واحدًا');
    }

    public function test_requester_cannot_approve_their_own_request_unless_configured(): void
    {
        $admin = $this->admin();
        $r = $this->request($admin);

        $this->approve($r, $admin)->assertStatus(422)->assertJsonValidationErrors(['request']);
        $this->as($admin)->postJson("/api/v1/merge-requests/{$r->id}/reject", ['notes' => 'x'])->assertStatus(422);
        $this->assertSame('pending', $r->fresh()->status->value);
        $this->assertNull($this->secondary->fresh()->merged_into_person_id);

        config(['access.allow_self_approval' => true]);
        $this->approve($r, $admin)->assertOk();
    }

    public function test_reject_needs_a_reason_and_leaves_everything_unchanged(): void
    {
        $this->usage();
        $r = $this->request();

        $this->as($this->reviewer)->postJson("/api/v1/merge-requests/{$r->id}/reject", [])->assertStatus(422);
        $this->as($this->reviewer)->postJson("/api/v1/merge-requests/{$r->id}/reject", ['notes' => 'الاسمان لشخصين مختلفين'])->assertOk()
            ->assertJsonPath('data.status.value', 'rejected')->assertJsonPath('data.decision_notes', 'الاسمان لشخصين مختلفين');

        $this->assertSame($this->secondary->id, $this->node->fresh()->person_id);
        $this->approve($r)->assertStatus(422)->assertJsonValidationErrors(['request']);
    }

    public function test_only_the_requester_can_withdraw_and_only_while_pending(): void
    {
        $r = $this->request();
        $other = $this->userWithRole('researcher');

        $this->as($other)->deleteJson("/api/v1/merge-requests/{$r->id}")->assertForbidden();
        $this->as($this->researcher)->deleteJson("/api/v1/merge-requests/{$r->id}")->assertOk()->assertJsonPath('data.status.value', 'rejected');
        $this->as($this->researcher)->deleteJson("/api/v1/merge-requests/{$r->id}")->assertStatus(422);
        $this->assertDatabaseHas('activity_logs', ['event' => 'merges.withdrawn']);
    }

    public function test_an_approved_request_cannot_be_approved_twice(): void
    {
        $this->usage();
        $r = $this->request();
        $this->approve($r)->assertOk();

        $this->approve($r)->assertStatus(422);
        $this->assertSame(3, PersonMergeRepoint::count(), 'لا تسجيل مكرر');
    }

    public function test_approval_fails_cleanly_if_a_person_was_archived_meanwhile(): void
    {
        $this->usage();
        $r = $this->request();
        $this->secondary->delete();

        $this->approve($r)->assertStatus(422);

        $this->assertSame('pending', $r->fresh()->status->value);
        $this->assertSame(0, PersonMergeRepoint::count());
        $this->assertSame($this->secondary->id, $this->node->fresh()->person_id);
    }

    // ------------------------------------------------------------- التعارضات

    public function test_rows_that_would_violate_rules_are_skipped_and_reported_not_forced(): void
    {
        $book = Book::factory()->create();
        BookContributor::create(['book_id' => $book->id, 'person_id' => $this->primary->id, 'role' => 'editor']);
        $dupe = BookContributor::create(['book_id' => $book->id, 'person_id' => $this->secondary->id, 'role' => 'editor']);
        $other = Book::factory()->create();
        $movable = BookContributor::create(['book_id' => $other->id, 'person_id' => $this->secondary->id, 'role' => 'editor']);
        // الأساسي شيخ للمدموج: بعد الدمج يصير شيخًا لنفسه، والقيد يمنعه
        $self = PersonRelation::create(['teacher_person_id' => $this->primary->id, 'student_person_id' => $this->secondary->id]);
        $fine = PersonRelation::create(['teacher_person_id' => $this->secondary->id, 'student_person_id' => Person::factory()->create()->id]);
        $r = $this->request();

        $res = $this->approve($r)->assertOk();

        $this->assertSame($this->secondary->id, $dupe->fresh()->person_id, 'مكرر: يُترك');
        $this->assertSame($this->primary->id, $movable->fresh()->person_id);
        $this->assertSame($this->secondary->id, $self->fresh()->student_person_id);
        $this->assertSame($this->primary->id, $fine->fresh()->teacher_person_id);
        $skipped = collect($res->json('data.result.skipped'))->keyBy('table');
        $this->assertSame([$dupe->id], $skipped['book_contributors']['row_ids']);
        $this->assertSame([$self->id], $skipped['person_relations']['row_ids']);
    }

    public function test_only_one_preferred_date_claim_per_type_remains_and_revert_restores_it(): void
    {
        PersonDateClaim::create(['person_id' => $this->primary->id, 'claim_type' => 'death', 'year' => 256, 'calendar' => 'hijri', 'certainty' => 'certain', 'is_preferred' => true]);
        $claim = PersonDateClaim::create(['person_id' => $this->secondary->id, 'claim_type' => 'death', 'year' => 257, 'calendar' => 'hijri', 'certainty' => 'uncertain', 'is_preferred' => true]);
        $r = $this->request();

        $this->approve($r)->assertOk();
        $this->assertSame([$this->primary->id, false], [$claim->fresh()->person_id, (bool) $claim->fresh()->is_preferred]);
        $this->assertSame(1, PersonDateClaim::where('person_id', $this->primary->id)->where('claim_type', 'death')->where('is_preferred', true)->count());

        $this->as($this->admin())->postJson("/api/v1/merge-requests/{$r->id}/revert", ['notes' => 'خطأ في الدمج'])->assertOk();
        $this->assertSame([$this->secondary->id, true], [$claim->fresh()->person_id, (bool) $claim->fresh()->is_preferred]);
    }

    public function test_people_merged_earlier_into_the_secondary_are_flattened_to_the_primary(): void
    {
        $child = Person::factory()->create(['merged_into_person_id' => $this->secondary->id]);
        $r = $this->request();

        $this->approve($r)->assertOk();
        $this->assertSame($this->primary->id, $child->fresh()->merged_into_person_id, 'لا سلاسل دمج');

        $this->as($this->admin())->postJson("/api/v1/merge-requests/{$r->id}/revert", ['notes' => 'تراجع'])->assertOk();
        $this->assertSame($this->secondary->id, $child->fresh()->merged_into_person_id);
    }

    // ------------------------------------------------------------- التراجع

    public function test_revert_restores_every_moved_row_and_removes_what_the_merge_added(): void
    {
        $this->usage();
        $r = $this->request();
        $this->approve($r)->assertOk();

        $res = $this->as($this->admin())->postJson("/api/v1/merge-requests/{$r->id}/revert", ['notes' => 'اتضح أنهما شخصان'])->assertOk()
            ->assertJsonPath('data.status.value', 'reverted')->assertJsonPath('data.result.revert.restored_rows', 3);

        $this->assertSame([$this->secondary->id, $this->secondary->id, $this->secondary->id], [$this->node->fresh()->person_id, $this->book->fresh()->author_person_id, $this->ijaza->fresh()->grantor_person_id]);
        $this->assertNull($this->secondary->fresh()->merged_into_person_id);
        $this->assertSame(0, PersonNameVariant::where('person_id', $this->primary->id)->count());
        $this->assertSame([], $res->json('data.result.revert.conflicts'));
        $this->assertContains($this->secondary->id, $this->ids($this->as($this->reviewer)->getJson('/api/v1/persons?per_page=100')));
        $this->assertDatabaseHas('activity_logs', ['event' => 'merges.reverted']);
        $this->assertSame('الشخص نفسه', $r->fresh()->reason, 'سجل الطلب يبقى');
    }

    public function test_revert_leaves_rows_changed_after_the_merge_and_reports_them(): void
    {
        $this->usage();
        $r = $this->request();
        $this->approve($r)->assertOk();
        $third = Person::factory()->create();
        $this->node->fresh()->update(['person_id' => $third->id]); // باحث أعاد ربط الحلقة بعد الدمج

        $res = $this->as($this->admin())->postJson("/api/v1/merge-requests/{$r->id}/revert", ['notes' => 'تراجع'])->assertOk();

        $this->assertSame($third->id, $this->node->fresh()->person_id, 'تعديل لاحق لا يُمحى');
        $this->assertSame($this->secondary->id, $this->book->fresh()->author_person_id);
        $this->assertSame(2, $res->json('data.result.revert.restored_rows'));
        $this->assertSame('asnad_nodes', $res->json('data.result.revert.conflicts.0.table'));
        $this->assertSame($this->node->id, $res->json('data.result.revert.conflicts.0.row_id'));
    }

    public function test_revert_rules(): void
    {
        $admin = $this->admin();
        $pending = $this->request();
        $this->as($admin)->postJson("/api/v1/merge-requests/{$pending->id}/revert", ['notes' => 'x'])->assertStatus(422);

        $r = $this->request(null, Person::factory()->create(), Person::factory()->create());
        $this->approve($r)->assertOk();
        $this->as($admin)->postJson("/api/v1/merge-requests/{$r->id}/revert", [])->assertStatus(422)->assertJsonValidationErrors(['notes']);
        $this->as($admin)->postJson("/api/v1/merge-requests/{$r->id}/revert", ['notes' => 'تراجع'])->assertOk();
        $this->as($admin)->postJson("/api/v1/merge-requests/{$r->id}/revert", ['notes' => 'مرة ثانية'])->assertStatus(422);
    }

    public function test_revert_is_refused_when_the_primary_was_merged_elsewhere_afterwards(): void
    {
        $r = $this->request();
        $this->approve($r)->assertOk();
        $bigger = Person::factory()->create();
        $second = $this->request(null, $bigger, $this->primary);
        $this->approve($second)->assertOk();

        $this->as($this->admin())->postJson("/api/v1/merge-requests/{$r->id}/revert", ['notes' => 'تراجع'])->assertStatus(422)->assertJsonValidationErrors(['request']);
        $this->assertSame($bigger->id, $this->secondary->fresh()->merged_into_person_id);

        $this->as($this->admin())->postJson("/api/v1/merge-requests/{$second->id}/revert", ['notes' => 'تراجع'])->assertOk();
        $this->as($this->admin())->postJson("/api/v1/merge-requests/{$r->id}/revert", ['notes' => 'تراجع'])->assertOk();
        $this->assertNull($this->secondary->fresh()->merged_into_person_id);
    }

    public function test_a_second_merge_of_the_same_secondary_is_impossible_after_it_was_executed(): void
    {
        $r = $this->request();
        $this->approve($r)->assertOk();
        $again = $this->request(null, Person::factory()->create(), $this->secondary);

        $this->approve($again)->assertStatus(422);
    }

    // ------------------------------------------------------------- الرؤية والقوائم

    public function test_names_of_people_the_user_cannot_see_are_not_exposed(): void
    {
        $r = $this->request();
        $role = Role::create(['name' => 'merge-watcher', 'guard_name' => 'web']);
        $role->givePermissionTo(['merges.view', 'persons.view']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $watcher = $this->userWithRole('merge-watcher');

        $res = $this->as($watcher)->getJson("/api/v1/merge-requests/{$r->id}")->assertOk();

        $this->assertSame($this->primary->id, $res->json('data.primary.id'), 'الأساسي منشور فيُرى');
        $this->assertNull($res->json('data.secondary'));
        $this->assertNull($res->json('data.secondary_person_id'));
        $this->assertStringNotContainsString('أبو عبد الله', $res->getContent());
    }

    public function test_list_filters_and_pagination(): void
    {
        $a = $this->request();
        $b = $this->request(null, Person::factory()->create(), Person::factory()->create());
        $this->as($this->reviewer)->postJson("/api/v1/merge-requests/{$b->id}/reject", ['notes' => 'لا'])->assertOk();

        $this->assertSame([$a->id], $this->ids($this->as($this->reviewer)->getJson('/api/v1/merge-requests?status=pending')));
        $this->assertSame([$b->id], $this->ids($this->as($this->reviewer)->getJson('/api/v1/merge-requests?status=rejected')));
        $this->assertSame([$a->id], $this->ids($this->as($this->reviewer)->getJson("/api/v1/merge-requests?person_id={$this->secondary->id}")));
        $this->as($this->reviewer)->getJson('/api/v1/merge-requests?status=bogus')->assertStatus(422);
        $this->as($this->reviewer)->getJson('/api/v1/merge-requests?per_page=1')->assertOk()->assertJsonPath('meta.total', 2)->assertJsonCount(1, 'data');
        $this->as($this->reviewer)->getJson('/api/v1/merge-requests/999999')->assertNotFound();
    }

    public function test_view_permission_migration_grants_it_only_to_roles_that_already_have_merge_rights(): void
    {
        $withRequest = Role::create(['name' => 'old-researcher', 'guard_name' => 'web']);
        $withRequest->givePermissionTo('merges.request');
        $unrelated = Role::create(['name' => 'old-reader', 'guard_name' => 'web']);
        $unrelated->givePermissionTo('persons.view');
        DB::table('role_has_permissions')->where('permission_id', Permission::findByName('merges.view')->id)->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        (require base_path('database/migrations/2026_10_21_000001_add_merges_view_permission.php'))->up();

        $this->assertTrue($withRequest->fresh()->hasPermissionTo('merges.view'));
        $this->assertFalse($unrelated->fresh()->hasPermissionTo('merges.view'));
        $this->assertTrue(Role::findByName('reviewer')->hasPermissionTo('merges.view'));
        $this->assertTrue(Role::findByName('researcher')->hasPermissionTo('merges.view'));
        $this->assertFalse(Role::findByName('reader')->hasPermissionTo('merges.view'));
    }

    public function test_every_step_is_audited_with_the_actor(): void
    {
        $r = $this->request();
        $this->approve($r)->assertOk();
        $this->as($this->admin())->postJson("/api/v1/merge-requests/{$r->id}/revert", ['notes' => 'تراجع'])->assertOk();

        $events = ActivityLog::where('subject_id', $r->id)->where('event', 'like', 'merges.%')->orderBy('id')->pluck('event')->all();
        $this->assertSame(['merges.executed', 'merges.reverted'], $events);
    }
}
