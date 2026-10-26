<?php

namespace Tests\Feature\Export;

use App\Models\ActivityLog;
use App\Models\Asnad;
use App\Models\AsnadNode;
use App\Models\Book;
use App\Models\Ijaza;
use App\Models\Person;
use App\Models\User;
use Tests\Concerns\CreatesRecordFixtures;
use Tests\DatabaseTestCase;

class ExportTest extends DatabaseTestCase
{
    use CreatesRecordFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpRecords();
    }

    /** قارئ بصلاحية التصدير فقط: يرى المنشور وحده */
    private function limitedExporter(): User
    {
        $u = $this->userWithRole('reader');
        $u->givePermissionTo('exports.create');

        return $u;
    }

    /** @param list<Person|string|null> $chain الأقرب إلينا أولًا */
    private function asnad(array $chain, array $attributes = [], bool $published = true): Asnad
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

    private function p(string $name, array $extra = []): Person
    {
        return Person::factory()->published()->create(['primary_name' => $name] + $extra);
    }

    private function csvRows(string $content): array
    {
        $this->assertStringStartsWith("\xEF\xBB\xBF", $content);
        $fh = fopen('php://temp', 'r+');
        fwrite($fh, substr($content, 3));
        rewind($fh);
        $rows = [];
        while (($r = fgetcsv($fh, null, ',', '"', '\\')) !== false) {
            $rows[] = $r;
        }

        return $rows;
    }

    // ------------------------------------------------------------- الصلاحيات

    public function test_only_roles_with_the_export_permission_can_export(): void
    {
        foreach (['asnads', 'persons', 'ijazas'] as $kind) {
            $this->as($this->reader)->get("/api/v1/exports/{$kind}")->assertForbidden();
            $this->as($this->researcher)->get("/api/v1/exports/{$kind}")->assertOk();
            $this->as($this->reviewer)->get("/api/v1/exports/{$kind}")->assertOk();
            app('auth')->forgetGuards();
            $this->getJson("/api/v1/exports/{$kind}")->assertUnauthorized();
        }
    }

    public function test_export_is_read_only(): void
    {
        $this->as($this->researcher)->postJson('/api/v1/exports/asnads', [])->assertStatus(405);
    }

    public function test_validation(): void
    {
        $this->as($this->researcher)->getJson('/api/v1/exports/asnads?format=xlsx')->assertStatus(422)->assertJsonValidationErrors(['format']);
        $this->as($this->researcher)->getJson('/api/v1/exports/persons?format=txt')->assertStatus(422)->assertJsonValidationErrors(['format']);
        $this->as($this->researcher)->getJson('/api/v1/exports/asnads?ids=5')->assertStatus(422)->assertJsonValidationErrors(['ids']);
        $this->as($this->researcher)->getJson('/api/v1/exports/persons?death_from=abc')->assertStatus(422);
    }

    // ------------------------------------------------------------- الأسانيد

    public function test_asnads_csv_has_chain_counts_and_safe_headers(): void
    {
        $a = $this->asnad([$this->p('مالك'), 'راوٍ مجهول', $this->p('نافع')], ['title' => 'سند الموطأ']);

        $res = $this->as($this->researcher)->get('/api/v1/exports/asnads?format=csv')->assertOk();
        $this->assertStringContainsString('text/csv', $res->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment; filename="sanad-asnads-', $res->headers->get('Content-Disposition'));
        $this->assertSame('nosniff', $res->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString('no-store', $res->headers->get('Cache-Control'));

        $rows = $this->csvRows($res->getContent());
        $row = collect($rows)->firstWhere(0, (string) $a->id);
        $this->assertSame('سند الموطأ', $row[1]);
        $this->assertSame('مالك ← راوٍ مجهول (غير محسوم) ← نافع', $row[5]);
        $this->assertSame('3', $row[6], 'عدد الرجال');
        $this->assertSame('1', $row[7], 'الوسائط');
        $this->assertSame('لا', $row[9], 'غير مكتمل لأن فيه حلقة غير محسومة');
    }

    public function test_node_csv_has_one_row_per_node_in_order(): void
    {
        $a = $this->asnad([$this->p('أ'), 'مجهول', $this->p('ج')]);

        $rows = $this->csvRows($this->as($this->researcher)->get('/api/v1/exports/asnads?format=csv_nodes&ids[]='.$a->id)->assertOk()->getContent());

        $this->assertCount(4, $rows);
        $this->assertSame(['1', 'أ', 'مربوط'], [$rows[1][2], $rows[1][3], $rows[1][5]]);
        $this->assertSame(['2', 'مجهول (غير محسوم)', 'غير محسوم'], [$rows[2][2], $rows[2][3], $rows[2][5]]);
        $this->assertSame('3', $rows[3][2]);
    }

    public function test_text_export_is_readable_arabic_with_counts(): void
    {
        $a = $this->asnad([$this->p('مالك', ['death_year_h' => 179]), 'مجهول'], ['title' => 'سند تجريبي']);

        $text = $this->as($this->researcher)->get('/api/v1/exports/asnads?format=txt&ids[]='.$a->id)->assertOk()->getContent();

        $this->assertStringContainsString('text/plain', $this->as($this->researcher)->get('/api/v1/exports/asnads?format=txt')->headers->get('Content-Type'));
        $this->assertStringContainsString("السند #{$a->id}: سند تجريبي", $text);
        $this->assertStringContainsString('1. مالك (ت 179هـ)', $text);
        $this->assertStringContainsString('2. مجهول (غير محسوم)', $text);
        $this->assertStringContainsString('عدد الرجال: 2', $text);
        $this->assertStringContainsString('غير مكتمل', $text);
    }

    public function test_json_export_has_meta_and_the_resource_shape(): void
    {
        $a = $this->asnad([$this->p('أ'), $this->p('ب')]);

        $res = $this->as($this->researcher)->getJson('/api/v1/exports/asnads?ids[]='.$a->id)->assertOk();

        $res->assertJsonPath('meta.count', 1)->assertJsonPath('meta.truncated', false)->assertJsonPath('data.0.id', $a->id)
            ->assertJsonPath('data.0.counts.persons_in_chain', 2)->assertJsonCount(2, 'data.0.nodes');
        $this->assertStringContainsString('application/json', $res->headers->get('Content-Type'));
        $this->assertStringContainsString('أ', $res->getContent(), 'العربية غير مهرّبة');
    }

    public function test_filters_by_ids_book_and_text(): void
    {
        $in = $this->asnad([$this->p('أ')], ['title' => 'حديث الصلاة', 'title_search' => 'حديث الصلاه']);
        $out = $this->asnad([$this->p('ب')], ['title' => 'شيء آخر']);
        $book = Book::factory()->create(['verification_status' => 'verified', 'publication_status' => 'published']);
        $book->asnads()->attach($in->id);

        $ids = fn (string $q) => collect($this->as($this->researcher)->getJson("/api/v1/exports/asnads?{$q}")->json('data'))->pluck('id')->all();

        $this->assertEqualsCanonicalizing([$in->id, $out->id], $ids('format=json'));
        $this->assertSame([$out->id], $ids('ids[]='.$out->id));
        $this->assertSame([$in->id], $ids('book_id='.$book->id));
        $this->assertSame([$in->id], $ids('q='.urlencode('الصلاة')));
    }

    public function test_limited_user_never_gets_hidden_records_or_hidden_person_names(): void
    {
        $secret = Person::factory()->create(['primary_name' => 'اسم سري']);
        $visible = $this->asnad([$this->p('ظاهر'), $secret]);
        $hiddenAsnad = $this->asnad([$this->p('ثالث')], ['title' => 'سند مخفي'], published: false);
        $user = $this->limitedExporter();

        foreach (['csv', 'csv_nodes', 'txt', 'json'] as $format) {
            $content = $this->as($user)->get('/api/v1/exports/asnads?format='.$format)->assertOk()->getContent();
            $this->assertStringNotContainsString('اسم سري', $content, $format);
            $this->assertStringNotContainsString('سند مخفي', $content, $format);
            $this->assertStringContainsString('ظاهر', $content, $format);
        }
        $csv = $this->as($user)->get('/api/v1/exports/asnads?format=csv')->getContent();
        $this->assertStringContainsString('ظاهر ← (علَم غير متاح)', $csv);
        $this->assertStringNotContainsString('سند مخفي', $this->as($user)->get('/api/v1/exports/asnads?ids[]='.$hiddenAsnad->id.'&format=txt')->getContent());
        // العدّ لا يتأثر بالإخفاء
        $json = $this->as($user)->getJson('/api/v1/exports/asnads?ids[]='.$visible->id)->json('data.0');
        $this->assertSame(2, $json['counts']['persons_in_chain']);
        $this->assertNull($json['nodes'][1]['person']);
    }

    public function test_internal_notes_stay_out_of_csv_and_out_of_json_for_limited_users(): void
    {
        $a = $this->asnad([$this->p('أ')], ['notes' => 'ملاحظة باحث داخلية']);
        $user = $this->limitedExporter();

        $this->assertStringNotContainsString('ملاحظة باحث داخلية', $this->as($this->researcher)->get('/api/v1/exports/asnads?format=csv')->getContent());
        $this->assertStringNotContainsString('ملاحظة باحث داخلية', $this->as($user)->get('/api/v1/exports/asnads?ids[]='.$a->id)->getContent());
    }

    public function test_formula_injection_in_stored_titles_is_neutralised(): void
    {
        $a = $this->asnad([$this->p('أ')], ['title' => '=HYPERLINK("http://x","y")']);

        $rows = $this->csvRows($this->as($this->researcher)->get('/api/v1/exports/asnads?format=csv&ids[]='.$a->id)->getContent());

        $this->assertSame('\'=HYPERLINK("http://x","y")', $rows[1][1]);
    }

    public function test_truncation_is_announced_in_headers_and_json_meta(): void
    {
        config(['access.export_limits.asnads' => 2]);
        foreach (range(1, 3) as $i) {
            $this->asnad([$this->p("ع$i")]);
        }

        $res = $this->as($this->researcher)->getJson('/api/v1/exports/asnads')->assertOk();

        $res->assertJsonPath('meta.count', 2)->assertJsonPath('meta.total', 3)->assertJsonPath('meta.truncated', true);
        $this->assertSame('1', $res->headers->get('X-Export-Truncated'));
        $this->assertSame('3', $res->headers->get('X-Export-Total'));
    }

    public function test_archived_asnads_are_not_exported(): void
    {
        $gone = $this->asnad([$this->p('أ')], ['title' => 'مؤرشف']);
        $gone->delete();

        $this->assertStringNotContainsString('مؤرشف', $this->as($this->admin())->get('/api/v1/exports/asnads?format=csv')->getContent());
    }

    // ------------------------------------------------------------- الأعلام

    public function test_persons_csv_lists_visible_canonical_people_only(): void
    {
        $shown = $this->p('محمد بن إسماعيل', ['kunyah' => 'أبو عبد الله', 'death_year_h' => 256, 'notes' => 'داخلي']);
        $shown->nameVariants()->create(['name' => 'البخاري', 'variant_type' => 'laqab']);
        $primary = $this->p('أساسي');
        $merged = $this->p('مدموج');
        $merged->forceFill(['merged_into_person_id' => $primary->id])->save();
        $draft = Person::factory()->create(['primary_name' => 'مسودة']);
        $archived = $this->p('مؤرشف');
        $archived->delete();

        $reader = $this->as($this->limitedExporter())->get('/api/v1/exports/persons?format=csv')->getContent();
        foreach (['مدموج', 'مسودة', 'مؤرشف', 'داخلي'] as $leak) {
            $this->assertStringNotContainsString($leak, $reader);
        }
        $rows = $this->csvRows($reader);
        $row = collect($rows)->firstWhere(0, (string) $shown->id);
        $this->assertSame(['محمد بن إسماعيل', 'أبو عبد الله', '256', 'البخاري'], [$row[1], $row[3], $row[6], $row[8]]);

        $team = $this->as($this->researcher)->get('/api/v1/exports/persons?format=csv')->getContent();
        $this->assertStringContainsString('مسودة', $team);
        $this->assertStringNotContainsString('مدموج', $team);
        $this->assertStringNotContainsString('داخلي', $team, 'الملاحظات لا تدخل CSV');
    }

    public function test_persons_filters_and_json(): void
    {
        $this->p('قديم', ['death_year_h' => 100]);
        $new = $this->p('حديث', ['death_year_h' => 900, 'name_search' => 'حديث']);

        $json = $this->as($this->researcher)->getJson('/api/v1/exports/persons?death_from=500&death_to=1000')->assertOk();

        $this->assertSame([$new->id], array_column($json->json('data'), 'id'));
        $rows = $this->csvRows($this->as($this->researcher)->get('/api/v1/exports/persons?format=csv&q='.urlencode('حديث'))->getContent());
        $this->assertSame(['حديث'], array_column(array_slice($rows, 1), 1));
    }

    // ------------------------------------------------------------- الإجازات

    public function test_ijazas_csv_masks_hidden_grantor_and_skips_hidden_ijazas(): void
    {
        $secret = Person::factory()->create(['primary_name' => 'مجيز سري']);
        $shown = Ijaza::factory()->create(['grantor_person_id' => $secret->id, 'recipient_person_id' => $this->p('مجاز ظاهر')->id, 'verification_status' => 'verified', 'publication_status' => 'published', 'ijaza_year_h' => 1300]);
        Ijaza::factory()->create(['original_text' => 'نص مخفي']);

        $csv = $this->as($this->limitedExporter())->get('/api/v1/exports/ijazas?format=csv')->assertOk()->getContent();

        $this->assertStringNotContainsString('مجيز سري', $csv);
        $this->assertStringNotContainsString('نص مخفي', $csv);
        $row = collect($this->csvRows($csv))->firstWhere(0, (string) $shown->id);
        $this->assertSame(['(علَم غير متاح)', 'مجاز ظاهر', '1300'], [$row[3], $row[4], $row[5]]);
    }

    public function test_ijazas_filter_and_json(): void
    {
        $g = $this->p('شيخ');
        $a = Ijaza::factory()->create(['grantor_person_id' => $g->id]);
        Ijaza::factory()->create();

        $json = $this->as($this->researcher)->getJson('/api/v1/exports/ijazas?grantor_person_id='.$g->id)->assertOk();

        $this->assertSame([$a->id], array_column($json->json('data'), 'id'));
    }

    // ------------------------------------------------------------- التدقيق

    public function test_every_export_is_audited_without_its_content(): void
    {
        $this->asnad([$this->p('اسم في المحتوى')]);

        $this->as($this->researcher)->get('/api/v1/exports/asnads?format=csv&q='.urlencode('كلمة'))->assertOk();
        $this->as($this->researcher)->get('/api/v1/exports/persons?format=json')->assertOk();

        $logs = ActivityLog::where('event', 'exports.created')->orderBy('id')->get();
        $this->assertCount(2, $logs);
        $this->assertSame($this->researcher->id, $logs[0]->user_id);
        $this->assertSame(['asnads', 'csv'], [$logs[0]->changes['kind'], $logs[0]->changes['format']]);
        $this->assertSame('كلمة', $logs[0]->changes['filters']['q']);
        $this->assertSame('persons', $logs[1]->changes['kind']);
        $this->assertStringNotContainsString('اسم في المحتوى', json_encode($logs->pluck('changes')->all(), JSON_UNESCAPED_UNICODE));
    }

    public function test_a_refused_export_is_not_audited(): void
    {
        $this->as($this->reader)->get('/api/v1/exports/asnads')->assertForbidden();
        $this->as($this->researcher)->getJson('/api/v1/exports/asnads?format=bad')->assertStatus(422);

        $this->assertSame(0, ActivityLog::where('event', 'exports.created')->count());
    }
}
