<?php

namespace Tests\Feature\Database;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\DatabaseTestCase;

class SchemaTest extends DatabaseTestCase
{
    public const TABLES = [
        // التصنيفات
        'ijaza_types', 'sanad_types', 'chain_roles', 'transmission_modes', 'subject_categories',
        // الأشخاص
        'persons', 'person_name_variants', 'person_date_claims', 'person_relations',
        // الدمج
        'person_merge_suggestions', 'person_merge_requests', 'person_merge_repoints',
        // الكتب
        'books', 'book_titles', 'book_contributors', 'book_editions',
        // الإجازات
        'ijazas', 'ijaza_book',
        // الأسانيد
        'asnads', 'book_asnad', 'ijaza_asnad', 'asnad_nodes',
        // المصادر والمرفقات
        'sources', 'source_references', 'attachments', 'attachables',
        // المراجعة والتدقيق
        'reviews', 'activity_logs',
    ];

    public function test_connection_is_mysql_compatible(): void
    {
        $this->assertContains(DB::connection()->getDriverName(), ['mysql', 'mariadb']);
    }

    public function test_all_phase_two_tables_exist(): void
    {
        $this->assertCount(28, self::TABLES);

        foreach (self::TABLES as $table) {
            $this->assertTrue(Schema::hasTable($table), "الجدول غير موجود: {$table}");
        }
    }

    public function test_users_table_received_account_fields(): void
    {
        $this->assertTrue(Schema::hasColumns('users', ['is_active', 'last_login_at', 'deleted_at']));
    }

    public function test_expected_foreign_keys_exist(): void
    {
        $expected = [
            'persons_merged_into_person_id_foreign',
            'person_relations_teacher_person_id_foreign',
            'person_relations_student_person_id_foreign',
            'books_author_person_id_foreign',
            'ijazas_grantor_person_id_foreign',
            'ijazas_recipient_person_id_foreign',
            'ijaza_book_ijaza_id_foreign',
            'ijaza_book_book_id_foreign',
            'book_asnad_book_id_foreign',
            'book_asnad_asnad_id_foreign',
            'ijaza_asnad_ijaza_id_foreign',
            'ijaza_asnad_asnad_id_foreign',
            'asnad_nodes_asnad_id_foreign',
            'asnad_nodes_person_id_foreign',
            'source_references_source_id_foreign',
            'attachables_attachment_id_foreign',
        ];

        $actual = $this->constraintNames('FOREIGN KEY');

        foreach ($expected as $name) {
            $this->assertContains($name, $actual, "مفتاح خارجي مفقود: {$name}");
        }
    }

    public function test_check_constraints_are_installed(): void
    {
        $expected = [
            'chk_person_relations_distinct',
            'chk_merge_suggestions_order',
            'chk_merge_requests_distinct',
            'chk_asnad_nodes_has_identity',
            'chk_asnad_nodes_sequence_positive',
            'chk_asnads_count_range',
            'chk_asnads_count_positive',
        ];

        $actual = $this->constraintNames('CHECK');

        foreach ($expected as $name) {
            $this->assertContains($name, $actual, "قيد CHECK مفقود: {$name}");
        }
    }

    public function test_fulltext_indexes_are_installed(): void
    {
        $actual = collect(DB::select(
            "SELECT DISTINCT INDEX_NAME AS name FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND INDEX_TYPE = 'FULLTEXT'"
        ))->pluck('name')->all();

        foreach ([
            'persons_name_search_fulltext',
            'books_title_search_fulltext',
            'ijazas_text_search_fulltext',
            'asnads_chain_text_search_fulltext',
        ] as $name) {
            $this->assertContains($name, $actual, "فهرس FULLTEXT مفقود: {$name}");
        }
    }

    public function test_status_columns_default_to_draft_and_unpublished_at_database_level(): void
    {
        DB::table('persons')->insert([
            'primary_name' => 'سجل اختبار الحالة الافتراضية',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $row = DB::table('persons')->where('primary_name', 'سجل اختبار الحالة الافتراضية')->first();

        $this->assertSame('draft', $row->verification_status);
        $this->assertSame('unpublished', $row->publication_status);
    }

    /** @return list<string> */
    private function constraintNames(string $type): array
    {
        return collect(DB::select(
            'SELECT CONSTRAINT_NAME AS name FROM information_schema.TABLE_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_TYPE = ?',
            [$type]
        ))->pluck('name')->all();
    }
}
