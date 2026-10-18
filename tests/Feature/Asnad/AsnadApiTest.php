<?php

namespace Tests\Feature\Asnad;

use App\Models\ActivityLog;
use App\Models\Asnad;
use App\Models\AsnadNode;
use App\Models\Book;
use App\Models\ChainRole;
use App\Models\Ijaza;
use App\Models\Person;
use App\Models\SanadType;
use App\Models\Source;
use App\Models\SourceReference;
use Database\Seeders\LookupSeeder;
use Tests\Concerns\CreatesRecordFixtures;
use Tests\DatabaseTestCase;

class AsnadApiTest extends DatabaseTestCase
{
    use CreatesRecordFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpRecords();
    }

    /** @return list<Person> */
    private function people(int $n, bool $published = true): array
    {
        $factory = $published ? Person::factory()->published() : Person::factory();

        return $factory->count($n)->create()->all();
    }

    private function nodesPayload(array $people): array
    {
        return array_map(fn (Person $p) => ['person_id' => $p->id], $people);
    }

    private function makeAsnad(int $nodes = 4, array $attributes = [], bool $published = false): Asnad
    {
        $asnad = Asnad::factory()->withNodes($nodes)->create($attributes + ($published ? ['verification_status' => 'verified', 'publication_status' => 'published'] : []));

        return $asnad->refresh();
    }

    /** @return list<int> أرقام التسلسل كما هي في القاعدة، مرتبة */
    private function sequence(Asnad $asnad): array
    {
        return AsnadNode::where('asnad_id', $asnad->id)->orderBy('sequence_number')->pluck('sequence_number')->all();
    }

    /** @return list<int> معرّفات العناصر بترتيبها الحالي */
    private function nodeOrder(Asnad $asnad): array
    {
        return AsnadNode::where('asnad_id', $asnad->id)->orderBy('sequence_number')->pluck('id')->all();
    }

    // ---------------------------------------------------------------- إنشاء

    public function test_researcher_creates_asnad_with_nodes_in_the_given_order(): void
    {
        [$a, $b, $c] = $this->people(3);
        $type = SanadType::create(['name' => 'سند كتاب']);

        $res = $this->as($this->researcher)->postJson('/api/v1/asnads', [
            'title' => 'سَنَدُ الصَّحيح', 'sanad_type_id' => $type->id,
            'chain_text_original' => 'حَدَّثَنَا أَحْمَدُ عَنْ مُحَمَّدٍ',
            'nodes' => $this->nodesPayload([$a, $b, $c]),
        ])->assertCreated()
            ->assertJsonPath('data.verification_status.value', 'draft')
            ->assertJsonPath('data.publication_status.value', 'unpublished')
            ->assertJsonCount(3, 'data.nodes')
            ->assertJsonPath('data.counts.persons_in_chain', 3)
            ->assertJsonPath('data.counts.intermediaries', 1)
            ->assertJsonPath('data.counts.complete', true);

        $this->assertSame([$a->id, $b->id, $c->id], array_column($res->json('data.nodes'), 'person_id'));
        $this->assertSame([1, 2, 3], array_column($res->json('data.nodes'), 'sequence_number'));
        $asnad = Asnad::firstOrFail();
        $this->assertSame('حَدَّثَنَا أَحْمَدُ عَنْ مُحَمَّدٍ', $asnad->chain_text_original, 'النص الأصلي كما أُدخل');
        $this->assertSame('حدثنا احمد عن محمد', $asnad->chain_text_search);
        $this->assertSame('سند الصحيح', $asnad->title_search);
        $this->assertSame($this->researcher->id, $asnad->created_by);
        $this->assertDatabaseHas('activity_logs', ['event' => 'asnads.created', 'user_id' => $this->researcher->id]);
    }

    public function test_status_fields_are_ignored_on_create(): void
    {
        $this->as($this->researcher)->postJson('/api/v1/asnads', [
            'title' => 'س', 'verification_status' => 'verified', 'publication_status' => 'published', 'created_by' => 999,
        ])->assertCreated();

        $asnad = Asnad::firstOrFail();
        $this->assertSame('draft', $asnad->verification_status->value);
        $this->assertSame('unpublished', $asnad->publication_status->value);
        $this->assertSame($this->researcher->id, $asnad->created_by);
    }

    public function test_node_needs_a_person_or_a_written_name(): void
    {
        $this->as($this->researcher)->postJson('/api/v1/asnads', ['nodes' => [['text_segment' => 'x']]])
            ->assertStatus(422)->assertJsonValidationErrors(['nodes.0.unresolved_name']);
        $this->assertSame(0, Asnad::count());

        $this->as($this->researcher)->postJson('/api/v1/asnads', ['nodes' => [['unresolved_name' => 'راوٍ كما ورد']]])
            ->assertCreated()->assertJsonPath('data.counts.unresolved_positions', 1)->assertJsonPath('data.counts.complete', false);
    }

    public function test_create_rolls_back_everything_when_a_node_is_invalid(): void
    {
        [$a] = $this->people(1);

        $this->as($this->researcher)->postJson('/api/v1/asnads', [
            'title' => 'س', 'nodes' => [['person_id' => $a->id], ['person_id' => 999999]],
        ])->assertStatus(422);

        $this->assertSame(0, Asnad::count());
        $this->assertSame(0, AsnadNode::count());
    }

    public function test_create_rejects_bounds_outside_the_chain_and_saves_nothing(): void
    {
        $this->as($this->researcher)->postJson('/api/v1/asnads', [
            'nodes' => $this->nodesPayload($this->people(3)), 'count_from_seq' => 1, 'count_to_seq' => 9,
        ])->assertStatus(422)->assertJsonValidationErrors(['count_to_seq']);
        $this->assertSame(0, Asnad::count());

        $this->as($this->researcher)->postJson('/api/v1/asnads', ['count_from_seq' => 1])
            ->assertStatus(422)->assertJsonValidationErrors(['count_from_seq']);

        $this->as($this->researcher)->postJson('/api/v1/asnads', ['count_from_seq' => 3, 'count_to_seq' => 2, 'nodes' => $this->nodesPayload($this->people(3))])
            ->assertStatus(422)->assertJsonValidationErrors(['count_to_seq']);
    }

    public function test_chain_length_is_limited(): void
    {
        $nodes = array_fill(0, 501, ['unresolved_name' => 'ر']);

        $this->as($this->researcher)->postJson('/api/v1/asnads', ['nodes' => $nodes])->assertStatus(422)->assertJsonValidationErrors(['nodes']);
        $this->assertSame(0, Asnad::count());
    }

    public function test_node_lookups_must_be_active_and_existing(): void
    {
        $role = ChainRole::create(['name' => 'معطل', 'is_active' => false]);

        $this->as($this->researcher)->postJson('/api/v1/asnads', ['nodes' => [['unresolved_name' => 'ر', 'chain_role_id' => $role->id]]])
            ->assertStatus(422)->assertJsonValidationErrors(['nodes.0.chain_role_id']);
        $this->as($this->researcher)->postJson('/api/v1/asnads', ['nodes' => [['unresolved_name' => 'ر', 'transmission_mode_id' => 99999]]])
            ->assertStatus(422);
    }

    public function test_reader_cannot_write_anything(): void
    {
        $asnad = $this->makeAsnad(2, [], true);
        $node = $asnad->nodes()->first();

        $this->as($this->reader)->postJson('/api/v1/asnads', [])->assertForbidden();
        $this->as($this->reader)->putJson("/api/v1/asnads/{$asnad->id}", ['title' => 'x'])->assertForbidden();
        $this->as($this->reader)->postJson("/api/v1/asnads/{$asnad->id}/nodes", ['unresolved_name' => 'x'])->assertForbidden();
        $this->as($this->reader)->putJson("/api/v1/asnads/{$asnad->id}/nodes/order", ['order' => [1]])->assertForbidden();
        $this->as($this->reader)->deleteJson("/api/v1/asnads/{$asnad->id}/nodes/{$node->id}")->assertForbidden();
        $this->as($this->reader)->postJson('/api/v1/asnads/suggest-split', ['text' => 'حدثنا أحمد'])->assertForbidden();
        $this->as($this->reader)->deleteJson("/api/v1/asnads/{$asnad->id}")->assertForbidden();
    }

    // ---------------------------------------------------------------- تحديث السند

    public function test_update_changes_title_and_search_text_and_audits(): void
    {
        $asnad = $this->makeAsnad(2, ['title' => 'قديم', 'title_search' => 'قديم']);

        $this->as($this->researcher)->putJson("/api/v1/asnads/{$asnad->id}", ['title' => 'عُنوان جَديد'])->assertOk()->assertJsonPath('data.title', 'عُنوان جَديد');

        $this->assertSame('عنوان جديد', $asnad->fresh()->title_search);
        $log = ActivityLog::where('event', 'asnads.updated')->latest('id')->firstOrFail();
        $this->assertSame('قديم', $log->changes['title']['from']);

        ActivityLog::query()->delete();
        $this->as($this->researcher)->putJson("/api/v1/asnads/{$asnad->id}", ['title' => 'عُنوان جَديد'])->assertOk();
        $this->assertSame(0, ActivityLog::where('event', 'asnads.updated')->count());
    }

    public function test_nodes_cannot_be_replaced_through_asnad_update(): void
    {
        $asnad = $this->makeAsnad(2);

        $this->as($this->researcher)->putJson("/api/v1/asnads/{$asnad->id}", ['nodes' => [['unresolved_name' => 'x']]])
            ->assertStatus(422)->assertJsonValidationErrors(['nodes']);
        $this->assertSame(2, $asnad->nodes()->count());
    }

    public function test_original_chain_text_is_locked_once_saved_but_a_blank_can_be_filled(): void
    {
        $asnad = $this->makeAsnad(2, ['chain_text_original' => 'النص الأول']);
        $url = "/api/v1/asnads/{$asnad->id}";

        $this->as($this->researcher)->putJson($url, ['chain_text_original' => 'نص مختلف'])->assertStatus(422)->assertJsonValidationErrors(['chain_text_original']);
        $this->assertSame('النص الأول', $asnad->fresh()->chain_text_original);
        $this->as($this->researcher)->putJson($url, ['chain_text_original' => 'النص الأول', 'notes' => 'ن'])->assertOk();

        $blank = $this->makeAsnad(1);
        $this->as($this->researcher)->putJson("/api/v1/asnads/{$blank->id}", ['chain_text_original' => 'نص جديد مُدخَل'])->assertOk();
        $this->assertSame('نص جديد مُدخَل', $blank->fresh()->chain_text_original);
        $this->assertSame('نص جديد مدخل', $blank->fresh()->chain_text_search);
    }

    public function test_bounds_update_rules(): void
    {
        $asnad = $this->makeAsnad(4);
        $url = "/api/v1/asnads/{$asnad->id}";

        $this->as($this->researcher)->putJson($url, ['count_from_seq' => 2, 'count_to_seq' => 3])->assertOk()
            ->assertJsonPath('data.counts.persons_in_chain', 2)->assertJsonPath('data.counts.range.explicit', true);
        $this->as($this->researcher)->putJson($url, ['count_to_seq' => 9])->assertStatus(422)->assertJsonValidationErrors(['count_to_seq']);
        $this->as($this->researcher)->putJson($url, ['count_from_seq' => 4])->assertStatus(422);
        $this->assertSame([2, 3], [$asnad->fresh()->count_from_seq, $asnad->fresh()->count_to_seq], 'الرفض لا يغيّر المحفوظ');

        $this->as($this->researcher)->putJson($url, ['count_from_seq' => null, 'count_to_seq' => null])->assertOk()
            ->assertJsonPath('data.counts.persons_in_chain', 4)->assertJsonPath('data.counts.range.explicit', false);
    }

    public function test_bounds_cannot_be_set_on_an_empty_chain(): void
    {
        $asnad = $this->makeAsnad(0);

        $this->as($this->researcher)->putJson("/api/v1/asnads/{$asnad->id}", ['count_from_seq' => 1])->assertStatus(422);
    }

    // ---------------------------------------------------------------- إدراج العناصر

    public function test_adding_a_node_appends_at_the_end_by_default(): void
    {
        $asnad = $this->makeAsnad(3);
        [$p] = $this->people(1);

        $res = $this->as($this->researcher)->postJson("/api/v1/asnads/{$asnad->id}/nodes", ['person_id' => $p->id, 'unresolved_name' => 'كما ورد'])
            ->assertCreated()->assertJsonPath('data.counts.persons_in_chain', 4);

        $this->assertSame([1, 2, 3, 4], $this->sequence($asnad));
        $this->assertSame($p->id, $res->json('data.nodes.3.person_id'));
        $this->assertDatabaseHas('activity_logs', ['event' => 'asnads.node_added', 'subject_id' => $asnad->id]);
    }

    public function test_adding_a_node_at_a_position_shifts_the_rest_without_gaps_or_collisions(): void
    {
        $asnad = $this->makeAsnad(3);
        $before = $this->nodeOrder($asnad);
        [$p] = $this->people(1);

        $res = $this->as($this->researcher)->postJson("/api/v1/asnads/{$asnad->id}/nodes", ['person_id' => $p->id, 'position' => 1])->assertCreated();

        $this->assertSame([1, 2, 3, 4], $this->sequence($asnad));
        $after = $this->nodeOrder($asnad);
        $newId = AsnadNode::where('asnad_id', $asnad->id)->where('person_id', $p->id)->value('id');
        $this->assertSame(array_merge([$newId], $before), $after);
        $this->assertSame(1, $res->json('data.nodes.0.sequence_number'));

        $middle = $this->as($this->researcher)->postJson("/api/v1/asnads/{$asnad->id}/nodes", ['unresolved_name' => 'في الوسط', 'position' => 3])->assertCreated();
        $this->assertSame([1, 2, 3, 4, 5], $this->sequence($asnad));
        $this->assertSame('في الوسط', $middle->json('data.nodes.2.unresolved_name'));
    }

    public function test_invalid_node_position_is_rejected(): void
    {
        $asnad = $this->makeAsnad(2);

        $this->as($this->researcher)->postJson("/api/v1/asnads/{$asnad->id}/nodes", ['unresolved_name' => 'ر', 'position' => 4])->assertStatus(422)->assertJsonValidationErrors(['position']);
        $this->as($this->researcher)->postJson("/api/v1/asnads/{$asnad->id}/nodes", ['unresolved_name' => 'ر', 'position' => 0])->assertStatus(422);
        $this->as($this->researcher)->postJson("/api/v1/asnads/{$asnad->id}/nodes", ['unresolved_name' => 'ر', 'position' => 3])->assertCreated();
        $this->assertSame([1, 2, 3], $this->sequence($asnad));
    }

    public function test_adding_to_a_full_chain_is_rejected(): void
    {
        $asnad = $this->makeAsnad(0);
        $rows = [];
        for ($i = 1; $i <= 500; $i++) {
            $rows[] = ['asnad_id' => $asnad->id, 'sequence_number' => $i, 'unresolved_name' => "ر{$i}", 'created_at' => now(), 'updated_at' => now()];
        }
        AsnadNode::insert($rows);

        $this->as($this->researcher)->postJson("/api/v1/asnads/{$asnad->id}/nodes", ['unresolved_name' => 'زائد'])->assertStatus(422);
        $this->assertSame(500, $asnad->nodes()->count());
    }

    public function test_hidden_or_unknown_person_cannot_be_used_in_a_node(): void
    {
        $asnad = $this->makeAsnad(1);
        $merged = Person::factory()->create(['merged_into_person_id' => Person::factory()->create()->id]);

        $this->as($this->researcher)->postJson("/api/v1/asnads/{$asnad->id}/nodes", ['person_id' => $merged->id])->assertStatus(422);
        $this->as($this->researcher)->postJson("/api/v1/asnads/{$asnad->id}/nodes", ['person_id' => 99999])->assertStatus(422);
        $this->assertSame(1, $asnad->nodes()->count());
    }

    public function test_node_update_links_a_person_and_can_clear_it_only_with_a_written_name(): void
    {
        $asnad = $this->makeAsnad(0);
        $node = AsnadNode::create(['asnad_id' => $asnad->id, 'sequence_number' => 1, 'unresolved_name' => 'مجهول']);
        [$p] = $this->people(1);
        $url = "/api/v1/asnads/{$asnad->id}/nodes/{$node->id}";

        $this->as($this->researcher)->putJson($url, ['person_id' => $p->id])->assertOk()
            ->assertJsonPath('data.counts.complete', true)->assertJsonPath('data.nodes.0.person_id', $p->id);

        $this->as($this->researcher)->putJson($url, ['person_id' => null, 'unresolved_name' => null])->assertStatus(422);
        $this->assertSame($p->id, $node->fresh()->person_id);

        $this->as($this->researcher)->putJson($url, ['person_id' => null])->assertOk()->assertJsonPath('data.counts.complete', false);
        $this->assertDatabaseHas('activity_logs', ['event' => 'asnads.node_updated', 'subject_id' => $asnad->id]);
    }

    public function test_node_update_rejects_position_and_foreign_nodes(): void
    {
        $one = $this->makeAsnad(1);
        $other = $this->makeAsnad(1);
        $foreign = $other->nodes()->first();

        $this->as($this->researcher)->putJson("/api/v1/asnads/{$one->id}/nodes/{$foreign->id}", ['notes' => 'x'])->assertNotFound();
        $this->as($this->researcher)->putJson("/api/v1/asnads/{$one->id}/nodes/{$one->nodes()->first()->id}", ['position' => 5])->assertStatus(422);
        $this->as($this->researcher)->deleteJson("/api/v1/asnads/{$one->id}/nodes/{$foreign->id}")->assertNotFound();
        $this->assertSame(1, $other->nodes()->count());
    }

    // ---------------------------------------------------------------- حذف وترتيب

    public function test_removing_a_node_closes_the_gap(): void
    {
        $asnad = $this->makeAsnad(4);
        $ids = $this->nodeOrder($asnad);

        $this->as($this->researcher)->deleteJson("/api/v1/asnads/{$asnad->id}/nodes/{$ids[1]}")->assertOk()->assertJsonPath('data.counts.persons_in_chain', 3);

        $this->assertSame([1, 2, 3], $this->sequence($asnad));
        $this->assertSame([$ids[0], $ids[2], $ids[3]], $this->nodeOrder($asnad));
        $this->assertDatabaseHas('activity_logs', ['event' => 'asnads.node_removed', 'subject_id' => $asnad->id]);
    }

    public function test_removing_a_node_that_would_push_explicit_bounds_out_is_rejected_until_bounds_change(): void
    {
        $asnad = $this->makeAsnad(4, ['count_from_seq' => 1, 'count_to_seq' => 4]);
        $last = $this->nodeOrder($asnad)[3];

        $this->as($this->researcher)->deleteJson("/api/v1/asnads/{$asnad->id}/nodes/{$last}")->assertStatus(422);
        $this->assertSame(4, $asnad->nodes()->count());

        $this->as($this->researcher)->putJson("/api/v1/asnads/{$asnad->id}", ['count_to_seq' => 3])->assertOk();
        $this->as($this->researcher)->deleteJson("/api/v1/asnads/{$asnad->id}/nodes/{$last}")->assertOk();
        $this->assertSame([1, 2, 3], $this->sequence($asnad));
    }

    public function test_reorder_swaps_two_nodes_inside_a_transaction(): void
    {
        $asnad = $this->makeAsnad(4);
        [$a, $b, $c, $d] = $this->nodeOrder($asnad);

        $res = $this->as($this->researcher)->putJson("/api/v1/asnads/{$asnad->id}/nodes/order", ['order' => [$a, $c, $b, $d]])->assertOk();

        $this->assertSame([$a, $c, $b, $d], $this->nodeOrder($asnad));
        $this->assertSame([1, 2, 3, 4], $this->sequence($asnad));
        $this->assertSame([$a, $c, $b, $d], array_column($res->json('data.nodes'), 'id'));
        $log = ActivityLog::where('event', 'asnads.reordered')->firstOrFail();
        $this->assertSame([$a, $b, $c, $d], $log->changes['from']);
    }

    public function test_reordering_a_long_chain_in_reverse_never_collides_with_the_unique_constraint(): void
    {
        $asnad = $this->makeAsnad(30);
        $ids = $this->nodeOrder($asnad);

        $this->as($this->researcher)->putJson("/api/v1/asnads/{$asnad->id}/nodes/order", ['order' => array_reverse($ids)])->assertOk();

        $this->assertSame(array_reverse($ids), $this->nodeOrder($asnad));
        $this->assertSame(range(1, 30), $this->sequence($asnad));
    }

    public function test_reorder_with_a_wrong_set_of_ids_is_rejected_and_changes_nothing(): void
    {
        $asnad = $this->makeAsnad(3);
        $other = $this->makeAsnad(1);
        [$a, $b, $c] = $ids = $this->nodeOrder($asnad);
        $foreign = $other->nodes()->first()->id;

        $bad = [[$a, $b], [$a, $b, $c, $c], [$a, $b, $foreign], [$a, $a, $b], [$a, $b, $c, $foreign]];
        foreach ($bad as $order) {
            $this->as($this->researcher)->putJson("/api/v1/asnads/{$asnad->id}/nodes/order", ['order' => $order])->assertStatus(422);
        }
        $this->as($this->researcher)->putJson("/api/v1/asnads/{$asnad->id}/nodes/order", [])->assertStatus(422);

        $this->assertSame($ids, $this->nodeOrder($asnad));
        $this->assertSame([1, 2, 3], $this->sequence($asnad));
        $this->assertSame(0, ActivityLog::where('event', 'asnads.reordered')->count());
    }

    public function test_reorder_to_the_same_order_changes_and_logs_nothing(): void
    {
        $asnad = $this->makeAsnad(3);

        $this->as($this->researcher)->putJson("/api/v1/asnads/{$asnad->id}/nodes/order", ['order' => $this->nodeOrder($asnad)])->assertOk();
        $this->assertSame(0, ActivityLog::where('event', 'asnads.reordered')->count());
    }

    public function test_move_node_to_a_position(): void
    {
        $asnad = $this->makeAsnad(4);
        [$a, $b, $c, $d] = $this->nodeOrder($asnad);

        $this->as($this->researcher)->postJson("/api/v1/asnads/{$asnad->id}/nodes/{$d}/move", ['position' => 1])->assertOk();
        $this->assertSame([$d, $a, $b, $c], $this->nodeOrder($asnad));

        $this->as($this->researcher)->postJson("/api/v1/asnads/{$asnad->id}/nodes/{$d}/move", ['position' => 4])->assertOk();
        $this->assertSame([$a, $b, $c, $d], $this->nodeOrder($asnad));
        $this->assertSame([1, 2, 3, 4], $this->sequence($asnad));

        $this->as($this->researcher)->postJson("/api/v1/asnads/{$asnad->id}/nodes/{$d}/move", ['position' => 5])->assertStatus(422);
        $this->as($this->researcher)->postJson("/api/v1/asnads/{$asnad->id}/nodes/{$d}/move", [])->assertStatus(422);
    }

    public function test_explicit_bounds_stay_as_positions_when_nodes_are_reordered(): void
    {
        $asnad = $this->makeAsnad(4, ['count_from_seq' => 1, 'count_to_seq' => 2]);
        [$a, $b, $c, $d] = $this->nodeOrder($asnad);

        $this->as($this->researcher)->putJson("/api/v1/asnads/{$asnad->id}/nodes/order", ['order' => [$c, $d, $a, $b]])->assertOk()
            ->assertJsonPath('data.count_from_seq', 1)->assertJsonPath('data.count_to_seq', 2)->assertJsonPath('data.counts.persons_in_chain', 2);
    }

    // ---------------------------------------------------------------- الرؤية

    public function test_reader_sees_only_published_asnads_and_hidden_ones_are_404(): void
    {
        $published = $this->makeAsnad(2, [], true);
        $draft = $this->makeAsnad(2);

        $this->assertSame([$published->id], $this->ids($this->as($this->reader)->getJson('/api/v1/asnads')->assertOk()));
        foreach (['', '/counts', '/references'] as $suffix) {
            $this->as($this->reader)->getJson("/api/v1/asnads/{$draft->id}{$suffix}")->assertNotFound();
        }
        $this->as($this->researcher)->getJson("/api/v1/asnads/{$draft->id}")->assertOk();
        $this->assertEqualsCanonicalizing([$published->id, $draft->id], $this->ids($this->as($this->researcher)->getJson('/api/v1/asnads')));
    }

    public function test_reader_cannot_widen_visibility_with_filters(): void
    {
        $draft = $this->makeAsnad(2);

        $this->assertNotContains($draft->id, $this->ids($this->as($this->reader)->getJson('/api/v1/asnads?verification_status=draft&publication_status=unpublished')));
    }

    public function test_node_with_an_unpublished_person_discloses_nothing_about_that_person_to_reader(): void
    {
        $hidden = Person::factory()->create(['primary_name' => 'اسم علم غير منشور سري', 'notes' => 'ملاحظة بحثية سرية']);
        $shown = Person::factory()->published()->create();
        $asnad = $this->makeAsnad(0, [], true);
        AsnadNode::create(['asnad_id' => $asnad->id, 'sequence_number' => 1, 'person_id' => $shown->id]);
        AsnadNode::create(['asnad_id' => $asnad->id, 'sequence_number' => 2, 'person_id' => $hidden->id, 'notes' => 'ملاحظة العنصر السرية']);

        $reader = $this->as($this->reader)->getJson("/api/v1/asnads/{$asnad->id}")->assertOk();
        $nodes = $reader->json('data.nodes');
        $this->assertSame($shown->id, $nodes[0]['person_id']);
        $this->assertNull($nodes[1]['person_id'], 'لا يخرج معرّف علَم غير مرئي');
        $this->assertNull($nodes[1]['person']);
        $this->assertTrue($nodes[1]['is_linked']);
        $this->assertStringNotContainsString('اسم علم غير منشور سري', $reader->getContent());
        $this->assertStringNotContainsString('ملاحظة العنصر السرية', $reader->getContent());
        $this->assertArrayNotHasKey('notes', $nodes[1]);

        $team = $this->as($this->researcher)->getJson("/api/v1/asnads/{$asnad->id}")->assertOk();
        $this->assertSame($hidden->id, $team->json('data.nodes.1.person_id'));
        $this->assertSame('ملاحظة العنصر السرية', $team->json('data.nodes.1.notes'));
    }

    public function test_counts_stay_the_same_for_reader_even_when_a_person_is_hidden(): void
    {
        $asnad = $this->makeAsnad(4, [], true);

        $this->as($this->reader)->getJson("/api/v1/asnads/{$asnad->id}")->assertJsonPath('data.counts.persons_in_chain', 4)->assertJsonPath('data.counts.unique_persons', 4);
    }

    public function test_internal_asnad_fields_are_hidden_from_reader(): void
    {
        $asnad = $this->makeAsnad(1, ['notes' => 'ملاحظة داخلية', 'created_by' => $this->researcher->id], true);

        $this->as($this->reader)->getJson("/api/v1/asnads/{$asnad->id}")->assertJsonMissingPath('data.notes')->assertJsonMissingPath('data.created_by');
        $this->as($this->researcher)->getJson("/api/v1/asnads/{$asnad->id}")->assertJsonPath('data.notes', 'ملاحظة داخلية');
    }

    public function test_linked_books_and_ijazas_are_shown_only_when_visible_and_nested_notes_are_hidden_from_reader(): void
    {
        $asnad = $this->makeAsnad(2, [], true);
        $shownBook = Book::factory()->create(['verification_status' => 'verified', 'publication_status' => 'published']);
        $hiddenBook = Book::factory()->create(['title' => 'كتاب مخفي سري']);
        $asnad->books()->attach($shownBook, ['notes' => 'ملاحظة الرابط السرية']);
        $asnad->books()->attach($hiddenBook);
        $shownIjaza = Ijaza::factory()->create(['verification_status' => 'verified', 'publication_status' => 'published']);
        $asnad->ijazas()->attach($shownIjaza, ['notes' => 'ملاحظة رابط إجازة']);

        $reader = $this->as($this->reader)->getJson("/api/v1/asnads/{$asnad->id}")->assertOk();
        $this->assertSame([$shownBook->id], array_column($reader->json('data.books'), 'id'));
        $this->assertStringNotContainsString('كتاب مخفي سري', $reader->getContent());
        $this->assertArrayNotHasKey('notes', $reader->json('data.books.0'));
        $this->assertArrayNotHasKey('notes', $reader->json('data.ijazas.0'));
        $this->assertStringNotContainsString('ملاحظة الرابط السرية', $reader->getContent());

        $team = $this->as($this->researcher)->getJson("/api/v1/asnads/{$asnad->id}")->assertOk();
        $this->assertCount(2, $team->json('data.books'));
        $this->assertContains('ملاحظة الرابط السرية', array_column($team->json('data.books'), 'notes'));
    }

    // ---------------------------------------------------------------- أرشفة

    public function test_asnad_linked_to_books_or_ijazas_cannot_be_archived(): void
    {
        $admin = $this->admin();
        $asnad = $this->makeAsnad(2);
        $book = Book::factory()->create();
        $asnad->books()->attach($book);

        $res = $this->as($admin)->deleteJson("/api/v1/asnads/{$asnad->id}")->assertStatus(422);
        $this->assertStringContainsString('كتب: 1', $res->json('errors.asnad.0'));
        $this->assertNull($asnad->fresh()->deleted_at);

        $asnad->books()->detach();
        $asnad->ijazas()->attach(Ijaza::factory()->create());
        $this->as($admin)->deleteJson("/api/v1/asnads/{$asnad->id}")->assertStatus(422);
    }

    public function test_unlinked_asnad_is_archived_and_restored_by_admin_only(): void
    {
        $admin = $this->admin();
        $asnad = $this->makeAsnad(2);

        $this->as($this->researcher)->deleteJson("/api/v1/asnads/{$asnad->id}")->assertForbidden();
        $this->as($admin)->deleteJson("/api/v1/asnads/{$asnad->id}")->assertNoContent();
        $this->assertSoftDeleted($asnad);
        $this->assertNotContains($asnad->id, $this->ids($this->as($admin)->getJson('/api/v1/asnads')));
        $this->assertContains($asnad->id, $this->ids($this->as($admin)->getJson('/api/v1/asnads?status=trashed')));
        $this->as($this->researcher)->getJson('/api/v1/asnads?status=trashed')->assertForbidden();
        $this->assertSame(2, AsnadNode::where('asnad_id', $asnad->id)->count(), 'الأرشفة لا تحذف العناصر');

        $this->as($admin)->postJson("/api/v1/asnads/{$asnad->id}/restore")->assertOk()->assertJsonCount(2, 'data.nodes');
        $this->assertNull($asnad->fresh()->deleted_at);
    }

    // ---------------------------------------------------------------- بحث وفلترة

    public function test_search_in_chain_text_and_title_ignores_diacritics_and_word_order(): void
    {
        $a = $this->makeAsnad(1, ['title' => 'سَنَدُ الجَامِع', 'title_search' => 'سند الجامع', 'chain_text_original' => 'حَدَّثَنَا سُفْيَانُ عَنْ الزُّهْرِيِّ', 'chain_text_search' => 'حدثنا سفيان عن الزهري'], true);
        $b = $this->makeAsnad(1, ['title' => 'آخر', 'title_search' => 'اخر', 'chain_text_search' => 'اخبرنا مالك عن نافع'], true);

        $q = fn (string $term) => $this->ids($this->as($this->reader)->getJson('/api/v1/asnads?q='.urlencode($term))->assertOk());
        $this->assertSame([$a->id], $q('سفيان الزهري'));
        $this->assertSame([$a->id], $q('الزُّهرِيّ سُفيان'));
        $this->assertSame([$a->id], $q('سند الجامع'));
        $this->assertSame([$b->id], $q('مالك'));
        $this->assertSame([], $q('%'));
        $this->assertSame([], $q('غير موجود'));
    }

    public function test_filters_by_person_book_ijaza_and_type(): void
    {
        $type = SanadType::create(['name' => 'سند قراءة']);
        $a = $this->makeAsnad(0, ['sanad_type_id' => $type->id]);
        $b = $this->makeAsnad(0);
        [$p] = $this->people(1);
        AsnadNode::create(['asnad_id' => $a->id, 'sequence_number' => 1, 'person_id' => $p->id]);
        $book = Book::factory()->create(['verification_status' => 'verified', 'publication_status' => 'published']);
        $b->books()->attach($book);
        $ijaza = Ijaza::factory()->create(['verification_status' => 'verified', 'publication_status' => 'published']);
        $b->ijazas()->attach($ijaza);

        $f = fn (string $query) => $this->ids($this->as($this->researcher)->getJson('/api/v1/asnads?'.$query)->assertOk());
        $this->assertSame([$a->id], $f("person_id={$p->id}"));
        $this->assertSame([$a->id], $f("sanad_type_id={$type->id}"));
        $this->assertSame([$b->id], $f("book_id={$book->id}"));
        $this->assertSame([$b->id], $f("ijaza_id={$ijaza->id}"));
        $this->as($this->researcher)->getJson('/api/v1/asnads?per_page=1000')->assertStatus(422);
    }

    public function test_list_carries_compact_counts_and_pagination(): void
    {
        $this->makeAsnad(4, [], true);
        $this->makeAsnad(2, [], true);

        $res = $this->as($this->reader)->getJson('/api/v1/asnads?per_page=1&sort=recent')->assertOk()
            ->assertJsonPath('meta.total', 2)->assertJsonCount(1, 'data');
        $this->assertSame(2, $res->json('data.0.counts.persons_in_chain'));
        $this->assertArrayNotHasKey('definitions', $res->json('data.0.counts'));
    }

    // ---------------------------------------------------------------- الأعداد والتحليل

    public function test_counts_endpoint_returns_numbers_with_definitions_and_author_check(): void
    {
        [$a, $b, $author] = $this->people(3);
        $asnad = $this->makeAsnad(0, [], true);
        foreach ([$a, $b, $author] as $i => $p) {
            AsnadNode::create(['asnad_id' => $asnad->id, 'sequence_number' => $i + 1, 'person_id' => $p->id]);
        }
        $withAuthor = Book::factory()->create(['author_person_id' => $author->id, 'verification_status' => 'verified', 'publication_status' => 'published']);
        $without = Book::factory()->create(['author_person_id' => $a->id + 1000000 > 0 ? null : null, 'verification_status' => 'verified', 'publication_status' => 'published']);
        $asnad->books()->attach([$withAuthor->id, $without->id]);

        $res = $this->as($this->reader)->getJson("/api/v1/asnads/{$asnad->id}/counts")->assertOk()
            ->assertJsonPath('data.persons_in_chain', 3)->assertJsonPath('data.intermediaries', 1);

        $this->assertNotEmpty($res->json('data.definitions.intermediaries'));
        $byBook = collect($res->json('data.authors'))->keyBy('book_id');
        $this->assertTrue($byBook[$withAuthor->id]['author_in_chain']);
        $this->assertSame([3], $byBook[$withAuthor->id]['author_positions']);
        $this->assertFalse($byBook[$without->id]['author_in_chain']);
    }

    public function test_counts_authors_list_never_includes_books_hidden_from_the_user(): void
    {
        [$author] = $this->people(1);
        $asnad = $this->makeAsnad(0, [], true);
        AsnadNode::create(['asnad_id' => $asnad->id, 'sequence_number' => 1, 'person_id' => $author->id]);
        $hidden = Book::factory()->create(['author_person_id' => $author->id]);
        $asnad->books()->attach($hidden);

        $this->assertSame([], $this->as($this->reader)->getJson("/api/v1/asnads/{$asnad->id}/counts")->json('data.authors'));
        $this->assertCount(1, $this->as($this->researcher)->getJson("/api/v1/asnads/{$asnad->id}/counts")->json('data.authors'));
    }

    public function test_options_endpoint_exposes_count_definitions(): void
    {
        $res = $this->as($this->reader)->getJson('/api/v1/lookups/options')->assertOk();

        $this->assertNotEmpty($res->json('data.count_definitions.persons_in_chain'));
        $this->assertNotEmpty($res->json('data.count_definitions.unique_persons'));
    }

    public function test_summary_counts_unique_persons_across_a_set_and_hides_invisible_asnads(): void
    {
        [$a, $b, $c] = $this->people(3);
        $one = $this->makeAsnad(0, [], true);
        $two = $this->makeAsnad(0, [], true);
        $draft = $this->makeAsnad(0);
        foreach ([[$one, [$a, $b]], [$two, [$a, $c]], [$draft, [$c]]] as [$asnad, $people]) {
            foreach ($people as $i => $p) {
                AsnadNode::create(['asnad_id' => $asnad->id, 'sequence_number' => $i + 1, 'person_id' => $p->id]);
            }
        }
        $ids = "ids[]={$one->id}&ids[]={$two->id}&ids[]={$draft->id}";

        $reader = $this->as($this->reader)->getJson("/api/v1/asnads/summary?{$ids}")->assertOk();
        $this->assertSame(2, $reader->json('data.asnads_count'), 'المسودة غير مرئية للقارئ');
        $this->assertSame(3, $reader->json('data.unique_persons_in_set'));
        $this->assertSame(4, $reader->json('data.total_positions'));
        $this->assertSame(2, collect($reader->json('data.appearances'))->firstWhere('person_id', $a->id)['appearances']);

        $team = $this->as($this->researcher)->getJson("/api/v1/asnads/summary?{$ids}")->assertOk();
        $this->assertSame(3, $team->json('data.asnads_count'));

        $this->as($this->reader)->getJson('/api/v1/asnads/summary')->assertStatus(422);
        $this->as($this->reader)->getJson('/api/v1/asnads/summary?ids[]=abc')->assertStatus(422);
    }

    // ---------------------------------------------------------------- اقتراح التقسيم

    public function test_suggest_split_returns_segments_modes_and_visible_candidates_and_writes_nothing(): void
    {
        $this->seed(LookupSeeder::class);
        $ahmad = Person::factory()->published()->create(['primary_name' => 'أحمد بن حنبل', 'name_search' => 'احمد بن حنبل']);
        Person::factory()->create(['primary_name' => 'أحمد بن حنبل', 'name_search' => 'احمد بن حنبل', 'merged_into_person_id' => $ahmad->id]); // مدموج: لا يُقترح
        $sufyan = Person::factory()->published()->create(['primary_name' => 'سفيان', 'name_search' => 'سفيان']);

        $before = [Asnad::count(), AsnadNode::count(), Person::count(), ActivityLog::count()];
        $res = $this->as($this->researcher)->postJson('/api/v1/asnads/suggest-split', [
            'text' => 'حَدَّثَنَا أَحْمَدُ بْنُ حَنْبَلٍ عَنْ سُفْيَانَ عَنْ الزُّهْرِيِّ',
        ])->assertOk();
        $this->assertSame($before, [Asnad::count(), AsnadNode::count(), Person::count(), ActivityLog::count()], 'اقتراح فقط: لا يُحفظ شيء');

        $segments = $res->json('data.segments');
        $this->assertNotEmpty($res->json('data.note'));
        $this->assertCount(3, $segments);
        $this->assertSame([1, 2, 3], array_column($segments, 'sequence_number'));
        $this->assertSame('أَحْمَدُ بْنُ حَنْبَلٍ', $segments[0]['text_segment']);
        $this->assertSame('حَدَّثَنَا', $segments[0]['preceding_mode']);
        $this->assertSame('عَنْ', $segments[1]['preceding_mode']);
        $this->assertNotNull($segments[0]['transmission_mode_id'], 'حدثنا موجودة في جدول طرق التحمّل');
        $this->assertSame([$ahmad->id], array_column($segments[0]['candidates'], 'id'), 'المرشح الأصلي فقط، والمدموج لا يُقترح');
        $this->assertSame([$sufyan->id], array_column($segments[1]['candidates'], 'id'));
        $this->assertSame([], $segments[2]['candidates'], 'لا مرشح: لا يُخترع');
        $this->assertSame('الزُّهْرِيِّ', $segments[2]['unresolved_name']);
    }

    public function test_suggest_split_works_without_seeded_modes_and_handles_text_without_keywords(): void
    {
        $res = $this->as($this->researcher)->postJson('/api/v1/asnads/suggest-split', ['text' => 'أخبرنا مالك عن نافع'])->assertOk();
        $this->assertSame(['مالك', 'نافع'], array_column($res->json('data.segments'), 'text_segment'));
        $this->assertNull($res->json('data.segments.0.transmission_mode_id'));

        $plain = $this->as($this->researcher)->postJson('/api/v1/asnads/suggest-split', ['text' => 'مالك بن أنس'])->assertOk();
        $this->assertCount(1, $plain->json('data.segments'));
        $this->assertNull($plain->json('data.segments.0.preceding_mode'));
    }

    public function test_suggest_split_matches_multi_word_keywords_before_single_words(): void
    {
        $res = $this->as($this->researcher)->postJson('/api/v1/asnads/suggest-split', ['text' => 'قرأت على أبي عمرو أجاز لي ابن كثير'])->assertOk();

        $segments = $res->json('data.segments');
        $this->assertSame(['أبي عمرو', 'ابن كثير'], array_column($segments, 'text_segment'));
        $this->assertSame('قرأت على', $segments[0]['preceding_mode']);
        $this->assertSame('أجاز لي', $segments[1]['preceding_mode']);
    }

    public function test_suggest_split_validation(): void
    {
        $this->as($this->researcher)->postJson('/api/v1/asnads/suggest-split', [])->assertStatus(422)->assertJsonValidationErrors(['text']);
        $this->as($this->researcher)->postJson('/api/v1/asnads/suggest-split', ['text' => str_repeat('ا', 50001)])->assertStatus(422);
        $res = $this->as($this->researcher)->postJson('/api/v1/asnads/suggest-split', ['text' => 'حدثنا عن'])->assertOk();
        $this->assertSame([], $res->json('data.segments'));
    }

    public function test_split_suggestion_can_be_saved_only_by_an_explicit_create_call(): void
    {
        $this->seed(LookupSeeder::class);
        $suggest = $this->as($this->researcher)->postJson('/api/v1/asnads/suggest-split', ['text' => 'أخبرنا مالك عن نافع'])->assertOk();
        $this->assertSame(0, Asnad::count());

        $nodes = array_map(fn ($s) => [
            'unresolved_name' => $s['unresolved_name'], 'text_segment' => $s['text_segment'], 'transmission_mode_id' => $s['transmission_mode_id'],
        ], $suggest->json('data.segments'));
        $this->as($this->researcher)->postJson('/api/v1/asnads', ['nodes' => $nodes])->assertCreated()->assertJsonCount(2, 'data.nodes');
    }

    // ---------------------------------------------------------------- مراجع وتدقيق

    public function test_references_can_be_attached_to_asnads_and_ijazas(): void
    {
        $asnad = $this->makeAsnad(1, [], true);
        $ijaza = Ijaza::factory()->create(['verification_status' => 'verified', 'publication_status' => 'published']);
        $source = Source::factory()->create();

        foreach (['asnads' => $asnad, 'ijazas' => $ijaza] as $prefix => $entity) {
            $this->as($this->researcher)->postJson("/api/v1/{$prefix}/{$entity->id}/references", ['source_id' => $source->id, 'page' => '3'])
                ->assertCreated()->assertJsonPath('data.reference_status.value', 'unverified');
            $this->as($this->reader)->getJson("/api/v1/{$prefix}/{$entity->id}/references")->assertOk()->assertJsonCount(0, 'data');
        }
        $this->assertSame(['asnad', 'ijaza'], SourceReference::orderBy('id')->pluck('referenceable_type')->all());
    }

    public function test_every_structural_change_is_audited_with_the_actor(): void
    {
        $asnad = $this->makeAsnad(3);
        [$a, $b, $c] = $this->nodeOrder($asnad);

        $this->as($this->researcher)->postJson("/api/v1/asnads/{$asnad->id}/nodes", ['unresolved_name' => 'ر'])->assertCreated();
        $this->as($this->researcher)->putJson("/api/v1/asnads/{$asnad->id}/nodes/order", ['order' => [$b, $a, $c, $this->nodeOrder($asnad)[3]]])->assertOk();
        $this->as($this->researcher)->deleteJson("/api/v1/asnads/{$asnad->id}/nodes/{$c}")->assertOk();

        $events = ActivityLog::where('subject_id', $asnad->id)->orderBy('id')->pluck('event')->all();
        $this->assertSame(['asnads.node_added', 'asnads.reordered', 'asnads.node_removed'], $events);
        $this->assertSame([$this->researcher->id], ActivityLog::where('subject_id', $asnad->id)->pluck('user_id')->unique()->values()->all());
    }

    public function test_unknown_ids_are_404_and_guests_are_401(): void
    {
        $this->as($this->researcher)->getJson('/api/v1/asnads/999999')->assertNotFound();
        $this->as($this->researcher)->getJson('/api/v1/asnads/abc')->assertNotFound();
        $this->as($this->researcher)->putJson('/api/v1/asnads/999999/nodes/order', ['order' => [1]])->assertNotFound();

        app('auth')->forgetGuards();
        $this->getJson('/api/v1/asnads')->assertStatus(401);
        $this->postJson('/api/v1/asnads', [])->assertStatus(401);
        $this->postJson('/api/v1/asnads/suggest-split', ['text' => 'x'])->assertStatus(401);
        $this->getJson('/api/v1/asnads/summary?ids[]=1')->assertStatus(401);
    }
}
