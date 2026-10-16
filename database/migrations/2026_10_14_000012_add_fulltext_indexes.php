<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * فهارس FULLTEXT على الأعمدة المطبَّعة فقط (لا على النص الأصلي).
 * ملاحظة: InnoDB لا يُظهر الصفوف الجديدة في بحث FULLTEXT قبل اعتماد المعاملة (commit).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! $this->supportsFullText()) {
            return;
        }

        Schema::table('persons', fn (Blueprint $table) => $table->fullText('name_search', 'persons_name_search_fulltext'));
        Schema::table('books', fn (Blueprint $table) => $table->fullText('title_search', 'books_title_search_fulltext'));
        Schema::table('ijazas', fn (Blueprint $table) => $table->fullText('text_search', 'ijazas_text_search_fulltext'));
        Schema::table('asnads', fn (Blueprint $table) => $table->fullText('chain_text_search', 'asnads_chain_text_search_fulltext'));
    }

    public function down(): void
    {
        if (! $this->supportsFullText()) {
            return;
        }

        Schema::table('asnads', fn (Blueprint $table) => $table->dropFullText('asnads_chain_text_search_fulltext'));
        Schema::table('ijazas', fn (Blueprint $table) => $table->dropFullText('ijazas_text_search_fulltext'));
        Schema::table('books', fn (Blueprint $table) => $table->dropFullText('books_title_search_fulltext'));
        Schema::table('persons', fn (Blueprint $table) => $table->dropFullText('persons_name_search_fulltext'));
    }

    private function supportsFullText(): bool
    {
        return in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);
    }
};
