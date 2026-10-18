<?php

use App\Services\Search\ArabicNormalizer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * عنوان السند يُبحث فيه كبقية النصوص العربية (ق9)، فيحتاج عموده المطبَّع. إضافة فقط؛ عنوان السند الأصلي لا يُمس.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('asnads', function (Blueprint $table) {
            $table->string('title_search', 500)->nullable()->after('title');
            $table->index('title_search');
        });

        $normalizer = new ArabicNormalizer;
        DB::table('asnads')->orderBy('id')->each(function ($row) use ($normalizer) {
            DB::table('asnads')->where('id', $row->id)->update(['title_search' => $normalizer->normalize($row->title)]);
        });
    }

    public function down(): void
    {
        Schema::table('asnads', function (Blueprint $table) {
            $table->dropIndex(['title_search']);
            $table->dropColumn('title_search');
        });
    }
};
