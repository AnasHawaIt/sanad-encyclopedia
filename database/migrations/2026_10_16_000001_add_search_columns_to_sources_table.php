<?php

use App\Services\Search\ArabicNormalizer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * قرار ق9 (تطبيع البحث العربي) يشمل المصادر، لكن قاموس البيانات لم يعطها أعمدة *_search.
 * إضافة فقط: النص الأصلي في title وauthor لا يُمس، وتُملأ الأعمدة الجديدة للصفوف الموجودة.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sources', function (Blueprint $table) {
            $table->string('title_search', 500)->nullable()->after('title');
            $table->string('author_search', 500)->nullable()->after('author');
            $table->index('title_search');
        });

        $normalizer = new ArabicNormalizer;
        DB::table('sources')->orderBy('id')->each(function ($row) use ($normalizer) {
            DB::table('sources')->where('id', $row->id)->update([
                'title_search' => $normalizer->normalize($row->title),
                'author_search' => $normalizer->normalize($row->author),
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('sources', function (Blueprint $table) {
            $table->dropIndex(['title_search']);
            $table->dropColumn(['title_search', 'author_search']);
        });
    }
};
