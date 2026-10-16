<?php

namespace Tests\Feature\Database;

use App\Models\Asnad;
use App\Models\AsnadNode;
use App\Models\Person;
use Tests\DatabaseTestCase;

class AsnadStructureTest extends DatabaseTestCase
{
    public function test_chain_of_four_persons_is_stored_in_order(): void
    {
        $asnad = Asnad::factory()->withNodes(4)->create();

        $nodes = $asnad->nodes()->get();

        $this->assertCount(4, $nodes);
        $this->assertSame([1, 2, 3, 4], $nodes->pluck('sequence_number')->all());
        $this->assertCount(4, $nodes->pluck('person_id')->unique());
    }

    public function test_nodes_are_returned_by_sequence_even_when_inserted_out_of_order(): void
    {
        $asnad = Asnad::factory()->create();
        $people = Person::factory()->count(4)->create();

        foreach ([3, 1, 4, 2] as $sequence) {
            AsnadNode::factory()->create([
                'asnad_id' => $asnad->id,
                'sequence_number' => $sequence,
                'person_id' => $people[$sequence - 1]->id,
            ]);
        }

        $this->assertSame([1, 2, 3, 4], $asnad->nodes()->pluck('sequence_number')->all());
        $this->assertSame($people->pluck('id')->all(), $asnad->nodes()->pluck('person_id')->all());
    }

    public function test_duplicate_sequence_number_inside_one_asnad_is_rejected(): void
    {
        $asnad = Asnad::factory()->create();
        AsnadNode::factory()->create(['asnad_id' => $asnad->id, 'sequence_number' => 1]);

        $this->assertRejectedByDatabase(
            fn () => AsnadNode::factory()->create(['asnad_id' => $asnad->id, 'sequence_number' => 1])
        );

        // سند آخر يستطيع استخدام الرقم نفسه
        $other = Asnad::factory()->create();
        $node = AsnadNode::factory()->create(['asnad_id' => $other->id, 'sequence_number' => 1]);
        $this->assertNotNull($node->id);
    }

    public function test_same_person_may_appear_twice_in_one_chain_with_each_position_kept(): void
    {
        $asnad = Asnad::factory()->create();
        $person = Person::factory()->create();

        AsnadNode::factory()->create(['asnad_id' => $asnad->id, 'sequence_number' => 1, 'person_id' => $person->id]);
        AsnadNode::factory()->create(['asnad_id' => $asnad->id, 'sequence_number' => 2]);
        AsnadNode::factory()->create(['asnad_id' => $asnad->id, 'sequence_number' => 3, 'person_id' => $person->id]);

        $positions = $asnad->nodes()->where('person_id', $person->id)->pluck('sequence_number')->all();

        $this->assertSame([1, 3], $positions);
    }

    public function test_same_person_may_appear_in_many_asnads(): void
    {
        $person = Person::factory()->create();

        foreach (range(1, 3) as $ignored) {
            AsnadNode::factory()->create(['person_id' => $person->id]);
        }

        $this->assertSame(3, $person->asnadNodes()->count());
    }

    public function test_node_needs_a_person_or_an_unresolved_name(): void
    {
        $asnad = Asnad::factory()->create();

        $this->assertRejectedByDatabase(fn () => AsnadNode::factory()->create([
            'asnad_id' => $asnad->id, 'sequence_number' => 1, 'person_id' => null, 'unresolved_name' => null,
        ]));

        $this->assertRejectedByDatabase(fn () => AsnadNode::factory()->create([
            'asnad_id' => $asnad->id, 'sequence_number' => 1, 'person_id' => null, 'unresolved_name' => '',
        ]));

        $node = AsnadNode::factory()->unresolved()->create(['asnad_id' => $asnad->id, 'sequence_number' => 1]);
        $this->assertNull($node->person_id);
        $this->assertSame('راوٍ غير محقق', $node->unresolved_name);
    }

    public function test_sequence_number_must_be_positive(): void
    {
        $asnad = Asnad::factory()->create();

        $this->assertRejectedByDatabase(
            fn () => AsnadNode::factory()->create(['asnad_id' => $asnad->id, 'sequence_number' => 0])
        );
    }

    public function test_count_boundaries_must_be_ordered_and_positive(): void
    {
        $this->assertNotNull(Asnad::factory()->create()->id); // الحدّان فارغان: أول عنصر وآخر عنصر
        $this->assertNotNull(Asnad::factory()->create(['count_from_seq' => 2, 'count_to_seq' => 4])->id);
        $this->assertNotNull(Asnad::factory()->create(['count_from_seq' => 3, 'count_to_seq' => 3])->id);

        $this->assertRejectedByDatabase(fn () => Asnad::factory()->create(['count_from_seq' => 5, 'count_to_seq' => 2]));
        $this->assertRejectedByDatabase(fn () => Asnad::factory()->create(['count_from_seq' => 0, 'count_to_seq' => 3]));
    }

    public function test_a_person_used_in_a_chain_cannot_be_hard_deleted_but_can_be_archived(): void
    {
        $person = Person::factory()->create();
        AsnadNode::factory()->create(['person_id' => $person->id]);

        $this->assertRejectedByDatabase(fn () => Person::find($person->id)->forceDelete());

        // نسخة جديدة من النموذج عمداً: forceDelete() الفاشل يترك الراية forceDeleting مفعّلة
        // على النسخة نفسها، فيتحول أي delete() لاحق عليها إلى حذف نهائي.
        $archived = Person::find($person->id);
        $archived->delete();

        $this->assertSoftDeleted($archived);
        $this->assertSame(1, AsnadNode::where('person_id', $person->id)->count());
        // العنصر يظل يشير إلى الشخص المؤرشف
        $this->assertTrue(AsnadNode::where('person_id', $person->id)->first()->person->is($person));
    }

    public function test_force_deleting_an_asnad_removes_its_nodes_but_not_the_persons(): void
    {
        $asnad = Asnad::factory()->withNodes(3)->create();
        $personIds = $asnad->nodes()->pluck('person_id')->all();

        $asnad->forceDelete();

        $this->assertDatabaseMissing('asnad_nodes', ['asnad_id' => $asnad->id]);
        $this->assertSame(3, Person::whereIn('id', $personIds)->count());
    }

    public function test_original_chain_text_is_stored_verbatim(): void
    {
        $text = "حَدَّثَنَا الشَّيْخُ ـــ عَنْ ـــ فُلَانٍ\nأَخْبَرَنَا";
        $asnad = Asnad::factory()->create(['chain_text_original' => $text, 'chain_text_search' => 'حدثنا الشيخ عن فلان اخبرنا']);

        $this->assertSame($text, $asnad->fresh()->chain_text_original);
    }
}
