<?php

namespace Tests\Feature\Asnad;

use App\Models\ActivityLog;
use App\Models\Asnad;
use App\Models\AsnadNode;
use App\Models\Book;
use App\Models\Person;
use Tests\Concerns\CreatesRecordFixtures;
use Tests\DatabaseTestCase;

class AsnadComparisonTest extends DatabaseTestCase
{
    use CreatesRecordFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpRecords();
    }

    /** @param list<Person|string|null> $chain الأقرب إلينا أولًا؛ نص = اسم غير مربوط */
    private function asnad(array $chain, bool $published = true, array $attributes = []): Asnad
    {
        $asnad = Asnad::factory()->create($attributes + ($published ? ['verification_status' => 'verified', 'publication_status' => 'published'] : []));
        foreach ($chain as $i => $item) {
            AsnadNode::create([
                'asnad_id' => $asnad->id, 'sequence_number' => $i + 1,
                'person_id' => $item instanceof Person ? $item->id : null,
                'unresolved_name' => is_string($item) ? $item : null,
            ]);
        }

        return $asnad;
    }

    private function p(string $name): Person
    {
        return Person::factory()->published()->create(['primary_name' => $name]);
    }

    private function q(array $ids, string $extra = ''): string
    {
        return implode('&', array_map(fn ($id) => "ids[]={$id}", $ids)).$extra;
    }

    // ------------------------------------------------------------- المقارنة

    public function test_common_persons_are_found_with_their_positions_in_each_chain(): void
    {
        [$me, $x, $malik, $nafi, $umar] = [$this->p('أنا'), $this->p('شيخ'), $this->p('مالك'), $this->p('نافع'), $this->p('عمر')];
        $a = $this->asnad([$x, $malik, $nafi, $umar]);
        $b = $this->asnad([$me, $malik, $umar]);

        $res = $this->as($this->researcher)->getJson('/api/v1/asnads/compare?'.$this->q([$a->id, $b->id]))->assertOk();
        $data = $res->json('data');

        $common = collect($data['common_persons'])->keyBy('person.id');
        $this->assertEqualsCanonicalizing([$malik->id, $umar->id], $common->keys()->all());
        $this->assertSame([2], $common[$malik->id]['positions'][$a->id]);
        $this->assertSame([2], $common[$malik->id]['positions'][$b->id]);
        $this->assertSame([4], $common[$umar->id]['positions'][$a->id]);
        $this->assertSame([3], $common[$umar->id]['positions'][$b->id]);
        $this->assertSame($malik->id, $data['nearest_common']['person']['id'], 'الأقرب إلينا أصغر متوسط موضع');
        $this->assertSame($umar->id, $data['farthest_common']['person']['id']);
        $this->assertSame(2, $data['pairs'][0]['shared_persons']);
        $this->assertSame(2, collect($data['asnads'])->firstWhere('id', $a->id)['persons_only_here'], 'شيخ ونافع فقط في الأول');
        $this->assertSame(1, collect($data['asnads'])->firstWhere('id', $b->id)['persons_only_here'], 'أنا فقط في الثاني');
        $this->assertFalse($data['partial']);
        $this->assertNotEmpty($data['definitions']['matching']);
    }

    public function test_no_common_person_means_null_convergence(): void
    {
        $a = $this->asnad([$this->p('أ'), $this->p('ب')]);
        $b = $this->asnad([$this->p('ج'), $this->p('د')]);

        $data = $this->as($this->researcher)->getJson('/api/v1/asnads/compare?'.$this->q([$a->id, $b->id]))->assertOk()->json('data');

        $this->assertSame([], $data['common_persons']);
        $this->assertNull($data['nearest_common']);
        $this->assertNull($data['farthest_common']);
        $this->assertSame(0, $data['pairs'][0]['shared_persons']);
    }

    public function test_shared_versus_common_with_three_asnads(): void
    {
        [$all, $two] = [$this->p('في الثلاثة'), $this->p('في اثنين')];
        $a = $this->asnad([$all, $two]);
        $b = $this->asnad([$this->p('س'), $all, $two]);
        $c = $this->asnad([$this->p('ص'), $all]);

        $data = $this->as($this->researcher)->getJson('/api/v1/asnads/compare?'.$this->q([$a->id, $b->id, $c->id]))->assertOk()->json('data');

        $this->assertSame([$all->id], array_column(array_column($data['common_persons'], 'person'), 'id'));
        $this->assertSame([$two->id], array_column(array_column($data['shared_persons'], 'person'), 'id'));
        $this->assertCount(3, $data['pairs']);
    }

    public function test_unresolved_nodes_with_the_same_written_name_are_never_matched(): void
    {
        $a = $this->asnad(['سفيان', $this->p('مالك')]);
        $b = $this->asnad(['سفيان', $this->p('مالك')]);
        [$m1, $m2] = [$a->nodes()->where('sequence_number', 2)->value('person_id'), $b->nodes()->where('sequence_number', 2)->value('person_id')];
        $this->assertNotSame($m1, $m2);
        $shared = $this->p('مشترك');
        AsnadNode::where('asnad_id', $a->id)->where('sequence_number', 2)->update(['person_id' => $shared->id]);
        AsnadNode::where('asnad_id', $b->id)->where('sequence_number', 2)->update(['person_id' => $shared->id]);

        $data = $this->as($this->researcher)->getJson('/api/v1/asnads/compare?'.$this->q([$a->id, $b->id]))->assertOk()->json('data');

        $this->assertSame([$shared->id], array_column(array_column($data['common_persons'], 'person'), 'id'), 'لم يُطابَق إلا المربوط');
        $this->assertTrue($data['partial'], 'وجود غير مربوط يجعل النتيجة حدًّا أدنى');
        $this->assertSame('سفيان', $data['asnads'][0]['chain'][0]['unresolved_name']);
        $this->assertSame('unresolved', $data['asnads'][0]['chain'][0]['kind']);
    }

    public function test_merged_people_match_as_one_canonical_person(): void
    {
        $main = $this->p('الأساسي');
        $dupe = Person::factory()->create(['primary_name' => 'مكرر', 'merged_into_person_id' => $main->id]);
        $a = $this->asnad([$main, $this->p('ر1')]);
        $b = $this->asnad([$dupe, $this->p('ر2')]);

        $data = $this->as($this->researcher)->getJson('/api/v1/asnads/compare?'.$this->q([$a->id, $b->id]))->assertOk()->json('data');

        $this->assertSame([$main->id], array_column(array_column($data['common_persons'], 'person'), 'id'));
        $this->assertSame('الأساسي', $data['common_persons'][0]['person']['primary_name']);
    }

    public function test_comparison_respects_counting_bounds_unless_full_scope_is_requested(): void
    {
        [$a1, $a2, $a3] = [$this->p('أ1'), $this->p('أ2'), $this->p('أ3')];
        $shared = $this->p('مشترك');
        $one = $this->asnad([$a1, $a2, $shared], true, ['count_from_seq' => 1, 'count_to_seq' => 2]);
        $two = $this->asnad([$a3, $shared]);

        $counted = $this->as($this->researcher)->getJson('/api/v1/asnads/compare?'.$this->q([$one->id, $two->id]))->json('data');
        $this->assertSame([], $counted['common_persons'], 'المشترك خارج حدّي العدّ للسند الأول');
        $this->assertCount(2, $counted['asnads'][0]['chain']);

        $full = $this->as($this->researcher)->getJson('/api/v1/asnads/compare?'.$this->q([$one->id, $two->id], '&scope=full'))->json('data');
        $this->assertSame([$shared->id], array_column(array_column($full['common_persons'], 'person'), 'id'));
        $this->assertCount(3, $full['asnads'][0]['chain']);
    }

    public function test_person_repeated_in_a_chain_lists_all_positions(): void
    {
        $r = $this->p('مكرر في السند');
        $a = $this->asnad([$r, $this->p('و'), $r]);
        $b = $this->asnad([$r]);

        $data = $this->as($this->researcher)->getJson('/api/v1/asnads/compare?'.$this->q([$a->id, $b->id]))->json('data');

        $this->assertSame([1, 3], $data['common_persons'][0]['positions'][$a->id]);
        $this->assertSame(1.67, (float) $data['common_persons'][0]['mean_position'], 'متوسط المواضع 1 و3 و1');
    }

    public function test_compare_validation_limits(): void
    {
        $a = $this->asnad([$this->p('أ')]);

        $this->as($this->researcher)->getJson('/api/v1/asnads/compare')->assertStatus(422);
        $this->as($this->researcher)->getJson('/api/v1/asnads/compare?'.$this->q([$a->id]))->assertStatus(422);
        $this->as($this->researcher)->getJson('/api/v1/asnads/compare?'.$this->q([$a->id, $a->id]))->assertStatus(422);
        $this->as($this->researcher)->getJson('/api/v1/asnads/compare?'.$this->q(range(1, 21)))->assertStatus(422);
        $this->as($this->researcher)->getJson('/api/v1/asnads/compare?ids[]=abc&ids[]=2')->assertStatus(422);
        $this->as($this->researcher)->getJson('/api/v1/asnads/compare?'.$this->q([$a->id, 999999]))->assertStatus(422);
        $this->as($this->researcher)->getJson('/api/v1/asnads/compare?'.$this->q([$a->id, $a->id + 1], '&scope=nonsense'))->assertStatus(422);
    }

    // ------------------------------------------------------------- الرؤية

    public function test_reader_cannot_compare_hidden_asnads_and_gets_the_same_error_as_for_missing_ones(): void
    {
        $shown = $this->asnad([$this->p('أ')]);
        $draft = $this->asnad([$this->p('ب')], false);

        $missing = $this->as($this->reader)->getJson('/api/v1/asnads/compare?'.$this->q([$shown->id, 999999]));
        $hidden = $this->as($this->reader)->getJson('/api/v1/asnads/compare?'.$this->q([$shown->id, $draft->id]));

        $missing->assertStatus(422);
        $hidden->assertStatus(422);
        $this->assertSame($missing->json('errors'), $hidden->json('errors'));
        $this->as($this->researcher)->getJson('/api/v1/asnads/compare?'.$this->q([$shown->id, $draft->id]))->assertOk();
    }

    public function test_hidden_person_is_never_named_or_matched_for_reader(): void
    {
        $hidden = Person::factory()->create(['primary_name' => 'علم غير منشور سري']);
        $shown = $this->p('منشور');
        $a = $this->asnad([$shown, $hidden]);
        $b = $this->asnad([$this->p('آخر'), $hidden]);

        $reader = $this->as($this->reader)->getJson('/api/v1/asnads/compare?'.$this->q([$a->id, $b->id]))->assertOk();
        $this->assertStringNotContainsString('علم غير منشور سري', $reader->getContent());
        $this->assertStringNotContainsString('"person_id":'.$hidden->id, $reader->getContent());
        $this->assertSame([], $reader->json('data.common_persons'), 'المخفي لا يُطابَق للقارئ');
        $this->assertTrue($reader->json('data.partial'));
        $this->assertSame(2, $reader->json('data.hidden_nodes'));
        $this->assertSame('hidden', $reader->json('data.asnads.0.chain.1.kind'));
        $this->assertNull($reader->json('data.asnads.0.chain.1.person'));

        $team = $this->as($this->researcher)->getJson('/api/v1/asnads/compare?'.$this->q([$a->id, $b->id]))->assertOk();
        $this->assertSame([$hidden->id], array_column(array_column($team->json('data.common_persons'), 'person'), 'id'));
        $this->assertSame(0, $team->json('data.hidden_nodes'));
    }

    public function test_guests_get_401_and_nothing_is_written(): void
    {
        $a = $this->asnad([$this->p('أ')]);
        $b = $this->asnad([$this->p('ب')]);
        $before = ActivityLog::count();

        $this->as($this->researcher)->getJson('/api/v1/asnads/compare?'.$this->q([$a->id, $b->id]))->assertOk();
        $this->as($this->researcher)->getJson('/api/v1/asnads/tree?'.$this->q([$a->id, $b->id]))->assertOk();
        $this->assertSame($before, ActivityLog::count());

        app('auth')->forgetGuards();
        $this->getJson('/api/v1/asnads/compare?'.$this->q([$a->id, $b->id]))->assertStatus(401);
        $this->getJson('/api/v1/asnads/tree?'.$this->q([$a->id]))->assertStatus(401);
        $this->getJson('/api/v1/books/1/asnads/compare')->assertStatus(401);
    }

    // ------------------------------------------------------------- الشجرة

    public function test_tree_merges_shared_prefix_from_the_oldest_end_and_branches_after_it(): void
    {
        [$origin, $mid, $x, $y] = [$this->p('الأصل'), $this->p('الوسيط'), $this->p('س'), $this->p('ص')];
        $a = $this->asnad([$x, $mid, $origin]);   // الأقدم: الأصل ← الوسيط ← س
        $b = $this->asnad([$y, $mid, $origin]);   // الأقدم: الأصل ← الوسيط ← ص

        $data = $this->as($this->researcher)->getJson('/api/v1/asnads/tree?'.$this->q([$a->id, $b->id]))->assertOk()->json('data');
        $nodes = collect($data['nodes']);

        $this->assertSame(4, $data['stats']['nodes'], 'الأصل والوسيط مشتركان ثم فرعان');
        $this->assertSame(1, $data['stats']['roots']);
        $this->assertSame(2, $data['stats']['leaves']);
        $root = $nodes->firstWhere('parent_id', null);
        $this->assertSame($origin->id, $root['person']['id']);
        $this->assertEqualsCanonicalizing([$a->id, $b->id], $root['asnad_ids']);
        $this->assertSame(0, $root['depth']);
        $midNode = $nodes->firstWhere('parent_id', $root['id']);
        $this->assertSame($mid->id, $midNode['person']['id']);
        $leaves = $nodes->where('parent_id', $midNode['id']);
        $this->assertEqualsCanonicalizing([$x->id, $y->id], $leaves->pluck('person.id')->all());
        $this->assertSame([$a->id], $leaves->firstWhere('person.id', $x->id)['ends_asnad_ids']);
        $this->assertSame(2, $data['stats']['merged_appearances']);
        $ids = $nodes->pluck('id')->all();
        $this->assertSame($ids, collect($ids)->sort()->values()->all());
        $this->assertTrue(collect($data['nodes'])->every(fn ($n) => $n['parent_id'] === null || $n['parent_id'] < $n['id']), 'الأب يسبق أبناءه');
    }

    public function test_same_person_under_different_parents_is_not_merged_and_different_roots_stay_separate(): void
    {
        [$p, $q, $shared] = [$this->p('ع1'), $this->p('ع2'), $this->p('نفس العلَم')];
        $a = $this->asnad([$shared, $p]);
        $b = $this->asnad([$shared, $q]);

        $data = $this->as($this->researcher)->getJson('/api/v1/asnads/tree?'.$this->q([$a->id, $b->id]))->json('data');

        $this->assertSame(4, $data['stats']['nodes']);
        $this->assertSame(2, $data['stats']['roots']);
        $this->assertSame(0, $data['stats']['merged_appearances']);
    }

    public function test_tree_never_merges_unresolved_nodes_even_with_equal_written_names(): void
    {
        $root = $this->p('الأصل');
        $a = $this->asnad(['سفيان', $root]);
        $b = $this->asnad(['سفيان', $root]);

        $data = $this->as($this->researcher)->getJson('/api/v1/asnads/tree?'.$this->q([$a->id, $b->id]))->json('data');

        $this->assertSame(3, $data['stats']['nodes'], 'الأصل مشترك، والاسمان المكتوبان فرعان منفصلان');
        $this->assertTrue($data['partial']);
        $unresolved = collect($data['nodes'])->where('kind', 'unresolved');
        $this->assertCount(2, $unresolved);
        $this->assertSame(['سفيان', 'سفيان'], $unresolved->pluck('unresolved_name')->values()->all());
    }

    public function test_single_asnad_tree_is_a_path_and_empty_asnad_is_empty(): void
    {
        $a = $this->asnad([$this->p('أ'), $this->p('ب'), $this->p('ج')]);
        $empty = $this->asnad([]);

        $one = $this->as($this->researcher)->getJson('/api/v1/asnads/tree?'.$this->q([$a->id]))->assertOk()->json('data');
        $this->assertSame(3, $one['stats']['nodes']);
        $this->assertSame([0, 1, 2], array_column($one['nodes'], 'depth'));

        $none = $this->as($this->researcher)->getJson('/api/v1/asnads/tree?'.$this->q([$empty->id]))->assertOk()->json('data');
        $this->assertSame(0, $none['stats']['nodes']);
        $this->assertSame([], $none['nodes']);
    }

    public function test_a_very_long_chain_is_returned_flat_without_json_depth_problems(): void
    {
        $asnad = $this->asnad([]);
        $person = $this->p('كثير التكرار');
        $rows = [];
        for ($i = 1; $i <= 500; $i++) {
            $rows[] = ['asnad_id' => $asnad->id, 'sequence_number' => $i, 'person_id' => $person->id, 'created_at' => now(), 'updated_at' => now()];
        }
        AsnadNode::insert($rows);

        $data = $this->as($this->researcher)->getJson('/api/v1/asnads/tree?'.$this->q([$asnad->id]))->assertOk()->json('data');

        $this->assertSame(500, $data['stats']['nodes']);
        $this->assertSame(499, $data['nodes'][499]['depth']);
    }

    public function test_hidden_person_is_a_separate_unnamed_node_in_the_tree_for_reader(): void
    {
        $hidden = Person::factory()->create(['primary_name' => 'سري جدًا']);
        $a = $this->asnad([$this->p('ف1'), $hidden]);
        $b = $this->asnad([$this->p('ف2'), $hidden]);

        $reader = $this->as($this->reader)->getJson('/api/v1/asnads/tree?'.$this->q([$a->id, $b->id]))->assertOk();
        $this->assertStringNotContainsString('سري جدًا', $reader->getContent());
        $this->assertSame(4, $reader->json('data.stats.nodes'));
        $this->assertSame(2, $reader->json('data.stats.roots'));
        $this->assertSame(2, $reader->json('data.hidden_nodes'));

        $team = $this->as($this->researcher)->getJson('/api/v1/asnads/tree?'.$this->q([$a->id, $b->id]))->assertOk();
        $this->assertSame(3, $team->json('data.stats.nodes'));
    }

    public function test_tree_respects_bounds_and_scope(): void
    {
        [$x, $y, $z] = [$this->p('x'), $this->p('y'), $this->p('z')];
        $a = $this->asnad([$x, $y, $z], true, ['count_from_seq' => 2, 'count_to_seq' => 3]);

        $this->assertSame(2, $this->as($this->researcher)->getJson('/api/v1/asnads/tree?'.$this->q([$a->id]))->json('data.stats.nodes'));
        $this->assertSame(3, $this->as($this->researcher)->getJson('/api/v1/asnads/tree?'.$this->q([$a->id], '&scope=full'))->json('data.stats.nodes'));
    }

    // ------------------------------------------------------------- نطاق الكتاب

    public function test_book_compare_and_tree_use_only_visible_asnads_of_that_book(): void
    {
        $book = Book::factory()->create(['verification_status' => 'verified', 'publication_status' => 'published']);
        $shared = $this->p('مشترك');
        $a = $this->asnad([$this->p('أ'), $shared]);
        $b = $this->asnad([$this->p('ب'), $shared]);
        $draft = $this->asnad([$this->p('ج'), $shared], false);
        $other = $this->asnad([$this->p('خارج'), $shared]);
        $book->asnads()->attach([$a->id, $b->id, $draft->id]);

        $reader = $this->as($this->reader)->getJson("/api/v1/books/{$book->id}/asnads/compare")->assertOk();
        $this->assertSame(2, $reader->json('data.asnads_count'));
        $this->assertSame(2, $reader->json('data.book_asnads_total'), 'القارئ يرى اثنين فقط من ثلاثة، والعدد لا يكشف المسودة');
        $this->assertFalse($reader->json('data.truncated'));
        $this->assertSame([$shared->id], array_column(array_column($reader->json('data.common_persons'), 'person'), 'id'));

        $team = $this->as($this->researcher)->getJson("/api/v1/books/{$book->id}/asnads/compare")->assertOk();
        $this->assertSame(3, $team->json('data.asnads_count'));
        $this->assertNotContains($other->id, array_column($team->json('data.asnads'), 'id'));

        $tree = $this->as($this->reader)->getJson("/api/v1/books/{$book->id}/asnads/tree")->assertOk();
        $this->assertSame(3, $tree->json('data.stats.nodes'));
        $this->assertSame($book->id, $tree->json('data.book_id'));
    }

    public function test_book_with_fewer_than_two_visible_asnads_cannot_be_compared_but_can_have_a_tree(): void
    {
        $book = Book::factory()->create(['verification_status' => 'verified', 'publication_status' => 'published']);
        $book->asnads()->attach($this->asnad([$this->p('وحيد')])->id);

        $this->as($this->reader)->getJson("/api/v1/books/{$book->id}/asnads/compare")->assertOk()->assertJsonPath('data.asnads_count', 1)->assertJsonPath('data.common_persons', []);
        $this->as($this->reader)->getJson("/api/v1/books/{$book->id}/asnads/tree")->assertOk()->assertJsonPath('data.stats.nodes', 1);
    }

    public function test_hidden_book_is_404_for_reader_and_book_compare_is_truncated_at_the_limit(): void
    {
        $hiddenBook = Book::factory()->create();
        $this->as($this->reader)->getJson("/api/v1/books/{$hiddenBook->id}/asnads/compare")->assertNotFound();
        $this->as($this->reader)->getJson("/api/v1/books/{$hiddenBook->id}/asnads/tree")->assertNotFound();
        $this->as($this->reader)->getJson('/api/v1/books/999999/asnads/compare')->assertNotFound();

        $book = Book::factory()->create(['verification_status' => 'verified', 'publication_status' => 'published']);
        $ids = [];
        for ($i = 0; $i < 22; $i++) {
            $ids[] = $this->asnad([$this->p("ر{$i}")])->id;
        }
        $book->asnads()->attach($ids);

        $res = $this->as($this->reader)->getJson("/api/v1/books/{$book->id}/asnads/compare")->assertOk();
        $this->assertSame(20, $res->json('data.asnads_count'));
        $this->assertTrue($res->json('data.truncated'));
        $this->assertSame(22, $res->json('data.book_asnads_total'));
    }
}
