<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * قيود CHECK في migration مستقلة لتُعزل أي مشكلة فيها عن الجداول.
 * تعمل على MySQL 8.0.16+ وMariaDB 10.2+ فقط، وتُتجاوز على غيرهما.
 */
return new class extends Migration
{
    /** @var list<array{0: string, 1: string, 2: string}> */
    private array $checks = [
        ['person_relations', 'chk_person_relations_distinct', 'teacher_person_id <> student_person_id'],
        ['person_merge_suggestions', 'chk_merge_suggestions_order', 'person_a_id < person_b_id'],
        ['person_merge_requests', 'chk_merge_requests_distinct', 'primary_person_id <> secondary_person_id'],
        ['asnad_nodes', 'chk_asnad_nodes_has_identity', "person_id IS NOT NULL OR (unresolved_name IS NOT NULL AND unresolved_name <> '')"],
        ['asnad_nodes', 'chk_asnad_nodes_sequence_positive', 'sequence_number >= 1'],
        ['asnads', 'chk_asnads_count_range', 'count_from_seq IS NULL OR count_to_seq IS NULL OR count_from_seq <= count_to_seq'],
        ['asnads', 'chk_asnads_count_positive', '(count_from_seq IS NULL OR count_from_seq >= 1) AND (count_to_seq IS NULL OR count_to_seq >= 1)'],
    ];

    public function up(): void
    {
        if (! $this->supportsChecks()) {
            return;
        }

        foreach ($this->checks as [$table, $name, $expression]) {
            DB::statement("ALTER TABLE `{$table}` ADD CONSTRAINT `{$name}` CHECK ({$expression})");
        }
    }

    public function down(): void
    {
        if (! $this->supportsChecks()) {
            return;
        }

        foreach (array_reverse($this->checks) as [$table, $name]) {
            DB::statement("ALTER TABLE `{$table}` DROP CONSTRAINT `{$name}`");
        }
    }

    private function supportsChecks(): bool
    {
        return in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);
    }
};
