<?php

namespace Tests\Feature\Asnad;

use App\Models\ActivityLog;
use App\Models\Asnad;
use App\Models\AsnadNode;
use App\Models\Book;
use App\Models\Person;
use App\Services\Asnad\ChainCounter;
use Tests\DatabaseTestCase;

/**
 * حالات العدّ المعتمدة في قرار ق1. المصدر الوحيد للأرقام هو ChainCounter،
 * وكل اختبار هنا يتحقق من الأرقام وتعريفها معًا.
 */
class ChainCounterTest extends DatabaseTestCase
{
    private ChainCounter $counter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->counter = app(ChainCounter::class);
    }

    /** @param list<Person|null|string> $items شخص، أو null/نص لعنصر غير مربوط */
    private function chain(array $items, array $attributes = []): Asnad
    {
        $asnad = Asnad::factory()->create($attributes);
        foreach (array_values($items) as $i => $item) {
            AsnadNode::create([
                'asnad_id' => $asnad->id,
                'sequence_number' => $i + 1,
                'person_id' => $item instanceof Person ? $item->id : null,
                'unresolved_name' => $item instanceof Person ? null : ($item ?? 'راوٍ غير محقق'),
            ]);
        }

        return $asnad->refresh();
    }

    private function people(int $n): array
    {
        return Person::factory()->count($n)->create()->all();
    }

    public function test_four_men_chain_has_two_intermediaries(): void
    {
        $r = $this->counter->forAsnad($this->chain($this->people(4)));

        $this->assertSame(4, $r['persons_in_chain']);
        $this->assertSame(2, $r['intermediaries']);
        $this->assertSame(4, $r['unique_persons']);
        $this->assertSame(4, $r['total_positions']);
        $this->assertTrue($r['complete']);
        $this->assertSame([], $r['issues']);
        $this->assertSame(['from' => 1, 'to' => 4, 'explicit' => false], $r['range']);
    }

    public function test_two_men_have_no_intermediaries_and_one_man_has_none_to_count(): void
    {
        $two = $this->counter->forAsnad($this->chain($this->people(2)));
        $this->assertSame(2, $two['persons_in_chain']);
        $this->assertSame(0, $two['intermediaries']);

        $one = $this->counter->forAsnad($this->chain($this->people(1)));
        $this->assertSame(1, $one['persons_in_chain']);
        $this->assertNull($one['intermediaries'], 'الواسطات لا تُحسب إلا بوجود طرفين');
    }

    public function test_empty_chain_is_zero_and_incomplete(): void
    {
        $r = $this->counter->forAsnad($this->chain([]));

        $this->assertSame(0, $r['persons_in_chain']);
        $this->assertNull($r['intermediaries']);
        $this->assertSame(0, $r['unique_persons']);
        $this->assertFalse($r['complete']);
        $this->assertSame('empty_chain', $r['issues'][0]['code']);
    }

    public function test_author_is_not_counted_twice_when_he_is_a_node(): void
    {
        [$a, $b, $c, $author] = $this->people(4);
        $book = Book::factory()->create(['author_person_id' => $author->id]);
        $asnad = $this->chain([$a, $b, $c, $author]);

        $r = $this->counter->forAsnad($asnad);
        $check = $this->counter->authorCheck($asnad, collect([$book]))[0];

        $this->assertSame(4, $r['persons_in_chain'], 'المؤلف عنصر واحد لا اثنان');
        $this->assertSame(2, $r['intermediaries']);
        $this->assertTrue($check['author_in_chain']);
        $this->assertSame([4], $check['author_positions']);
    }

    public function test_author_is_never_added_when_the_chain_does_not_contain_him(): void
    {
        [$a, $b, $c, $author] = $this->people(4);
        $book = Book::factory()->create(['author_person_id' => $author->id]);
        $asnad = $this->chain([$a, $b, $c]);

        $r = $this->counter->forAsnad($asnad);
        $check = $this->counter->authorCheck($asnad, collect([$book]))[0];

        $this->assertSame(3, $r['persons_in_chain'], 'سند بلا مؤلف: لا يُضاف المؤلف تلقائيًا');
        $this->assertSame(1, $r['intermediaries']);
        $this->assertFalse($check['author_in_chain']);
        $this->assertSame([], $check['author_positions']);
    }

    public function test_author_outside_explicit_bounds_is_not_in_the_counted_chain(): void
    {
        [$a, $b, $c, $author] = $this->people(4);
        $book = Book::factory()->create(['author_person_id' => $author->id]);
        $asnad = $this->chain([$a, $b, $c, $author], ['count_from_seq' => 1, 'count_to_seq' => 3]);

        $this->assertFalse($this->counter->authorCheck($asnad, collect([$book]))[0]['author_in_chain']);
        $this->assertSame(3, $this->counter->forAsnad($asnad)['persons_in_chain']);
    }

    public function test_book_without_author_has_no_author_in_chain(): void
    {
        $book = Book::factory()->withoutAuthor()->create();
        $asnad = $this->chain($this->people(3));

        $check = $this->counter->authorCheck($asnad, collect([$book]))[0];
        $this->assertNull($check['author_person_id']);
        $this->assertFalse($check['author_in_chain']);
    }

    public function test_explicit_bounds_limit_the_count_for_a_chain_that_does_not_end_with_the_author(): void
    {
        $asnad = $this->chain($this->people(6), ['count_from_seq' => 2, 'count_to_seq' => 5]);

        $r = $this->counter->forAsnad($asnad);
        $this->assertSame(4, $r['persons_in_chain']);
        $this->assertSame(2, $r['intermediaries']);
        $this->assertSame(6, $r['total_positions']);
        $this->assertSame(['from' => 2, 'to' => 5, 'explicit' => true], $r['range']);
    }

    public function test_only_one_bound_given_defaults_the_other_end(): void
    {
        $from = $this->counter->forAsnad($this->chain($this->people(5), ['count_from_seq' => 3]));
        $this->assertSame(3, $from['persons_in_chain']);
        $this->assertSame(['from' => 3, 'to' => 5, 'explicit' => true], $from['range']);

        $to = $this->counter->forAsnad($this->chain($this->people(5), ['count_to_seq' => 2]));
        $this->assertSame(2, $to['persons_in_chain']);
        $this->assertSame(0, $to['intermediaries']);
    }

    public function test_repeated_person_counts_twice_in_the_chain_but_once_as_unique(): void
    {
        [$a, $b, $c] = $this->people(3);
        $r = $this->counter->forAsnad($this->chain([$a, $b, $a, $c]));

        $this->assertSame(4, $r['persons_in_chain']);
        $this->assertSame(2, $r['intermediaries']);
        $this->assertSame(3, $r['unique_persons']);
        $this->assertSame([['person_id' => $a->id, 'appearances' => 2, 'positions' => [1, 3]]], $r['repeated_persons']);
    }

    public function test_unresolved_node_is_a_position_but_marks_the_result_incomplete(): void
    {
        [$a, $c] = $this->people(2);
        $r = $this->counter->forAsnad($this->chain([$a, 'راوٍ مجهول', $c]));

        $this->assertSame(3, $r['persons_in_chain'], 'العنصر غير المربوط موضع في السلسلة');
        $this->assertSame(1, $r['intermediaries']);
        $this->assertSame(2, $r['unique_persons']);
        $this->assertTrue($r['unique_is_lower_bound']);
        $this->assertSame(1, $r['unresolved_positions']);
        $this->assertFalse($r['complete']);
        $this->assertSame('unresolved_nodes', $r['issues'][0]['code']);
        $this->assertNotSame('', $r['issues'][0]['message']);
    }

    public function test_two_unresolved_nodes_with_the_same_name_are_never_assumed_to_be_one_person(): void
    {
        $r = $this->counter->forAsnad($this->chain(['محمد', 'محمد']));

        $this->assertSame(2, $r['persons_in_chain']);
        $this->assertSame(0, $r['unique_persons'], 'غير المربوط لا يُحسب فريدًا ولا يُدمج بغيره');
        $this->assertSame([], $r['repeated_persons']);
        $this->assertTrue($r['unique_is_lower_bound']);
    }

    public function test_invalid_bounds_in_data_give_null_counts_and_an_issue_instead_of_wrong_numbers(): void
    {
        $asnad = $this->chain($this->people(3));
        // نتجاوز الخدمة عمدًا: قيد CHECK يسمح بهذا ولا يعرف عدد العناصر
        $asnad->forceFill(['count_to_seq' => 9])->save();

        $r = $this->counter->forAsnad($asnad->refresh());
        $this->assertNull($r['persons_in_chain']);
        $this->assertNull($r['unique_persons']);
        $this->assertFalse($r['complete']);
        $this->assertContains('invalid_bounds', array_column($r['issues'], 'code'));
    }

    public function test_merged_people_are_counted_as_one_canonical_person(): void
    {
        [$primary, $other] = $this->people(2);
        $duplicate = Person::factory()->create(['merged_into_person_id' => $primary->id]);
        $r = $this->counter->forAsnad($this->chain([$primary, $other, $duplicate]));

        $this->assertSame(3, $r['persons_in_chain']);
        $this->assertSame(2, $r['unique_persons']);
        $this->assertSame($primary->id, $r['repeated_persons'][0]['person_id']);
    }

    public function test_merge_chains_are_followed_to_the_final_person(): void
    {
        [$final] = $this->people(1);
        $middle = Person::factory()->create(['merged_into_person_id' => $final->id]);
        $oldest = Person::factory()->create(['merged_into_person_id' => $middle->id]);

        $r = $this->counter->forAsnad($this->chain([$final, $oldest]));
        $this->assertSame(1, $r['unique_persons']);
    }

    public function test_author_check_uses_canonical_people(): void
    {
        [$author] = $this->people(1);
        $oldRecord = Person::factory()->create(['merged_into_person_id' => $author->id]);
        $book = Book::factory()->create(['author_person_id' => $author->id]);

        $check = $this->counter->authorCheck($this->chain([$oldRecord]), collect([$book]))[0];
        $this->assertTrue($check['author_in_chain']);
    }

    public function test_set_counts_unique_persons_across_asnads_and_appearances(): void
    {
        [$a, $b, $c, $d] = $this->people(4);
        $one = $this->chain([$a, $b, $c]);
        $two = $this->chain([$a, $d, $c]);

        $r = $this->counter->forSet(collect([$one, $two]));

        $this->assertSame(2, $r['asnads_count']);
        $this->assertSame(4, $r['unique_persons_in_set']);
        $this->assertSame(6, $r['total_positions']);
        $this->assertFalse($r['unique_is_lower_bound']);
        $this->assertTrue($r['complete']);
        $byPerson = collect($r['appearances'])->keyBy('person_id');
        $this->assertSame(2, $byPerson[$a->id]['appearances']);
        $this->assertSame(2, $byPerson[$a->id]['asnads_count']);
        $this->assertSame(1, $byPerson[$b->id]['appearances']);
        $this->assertSame(3, $r['asnads'][$one->id]['persons_in_chain']);
        $this->assertNotEmpty($r['definitions']['unique_persons']);
    }

    public function test_set_counts_a_person_repeated_inside_one_chain_as_two_appearances(): void
    {
        [$a, $b] = $this->people(2);
        $r = $this->counter->forSet(collect([$this->chain([$a, $b, $a])]));

        $byPerson = collect($r['appearances'])->keyBy('person_id');
        $this->assertSame(2, $byPerson[$a->id]['appearances']);
        $this->assertSame(1, $byPerson[$a->id]['asnads_count']);
        $this->assertSame(2, $r['unique_persons_in_set']);
    }

    public function test_set_counts_respect_each_asnads_own_bounds(): void
    {
        [$a, $b, $c, $d] = $this->people(4);
        $bounded = $this->chain([$a, $b, $c, $d], ['count_from_seq' => 1, 'count_to_seq' => 2]);
        $other = $this->chain([$d]);

        $r = $this->counter->forSet(collect([$bounded, $other]));
        $this->assertSame(3, $r['unique_persons_in_set'], 'c وd خارج حدّي السند الأول، وd داخل الثاني');
        $this->assertSame(3, $r['total_positions']);
    }

    public function test_set_with_unresolved_nodes_is_flagged_as_lower_bound_and_incomplete(): void
    {
        [$a] = $this->people(1);
        $r = $this->counter->forSet(collect([$this->chain([$a, 'مجهول'])]));

        $this->assertTrue($r['unique_is_lower_bound']);
        $this->assertSame(1, $r['unresolved_positions']);
        $this->assertFalse($r['complete']);
        $this->assertSame(1, $r['unique_persons_in_set']);
    }

    public function test_empty_set_is_not_complete(): void
    {
        $r = $this->counter->forSet(collect());

        $this->assertSame(0, $r['unique_persons_in_set']);
        $this->assertFalse($r['complete']);
    }

    public function test_every_number_has_a_definition_in_arabic(): void
    {
        $definitions = $this->counter->definitions();

        foreach (['persons_in_chain', 'intermediaries', 'unique_persons', 'appearances', 'unresolved_positions', 'complete', 'range'] as $key) {
            $this->assertArrayHasKey($key, $definitions);
            $this->assertNotSame('', $definitions[$key]);
        }
        $this->assertStringContainsString('الواسطات', $definitions['intermediaries']);
    }

    public function test_counts_are_computed_from_nodes_not_stored(): void
    {
        $asnad = $this->chain($this->people(3));
        $this->assertSame(3, $this->counter->forAsnad($asnad)['persons_in_chain']);

        AsnadNode::create(['asnad_id' => $asnad->id, 'sequence_number' => 4, 'person_id' => Person::factory()->create()->id]);
        $this->assertSame(4, $this->counter->forAsnad($asnad->fresh())['persons_in_chain'], 'لا يتقادم الرقم بعد التعديل');
    }

    public function test_counter_does_not_write_to_the_database(): void
    {
        $asnad = $this->chain($this->people(3));
        $before = [AsnadNode::count(), Person::count(), ActivityLog::count()];

        $this->counter->forAsnad($asnad);
        $this->counter->forSet(collect([$asnad]));

        $this->assertSame($before, [AsnadNode::count(), Person::count(), ActivityLog::count()]);
    }
}
