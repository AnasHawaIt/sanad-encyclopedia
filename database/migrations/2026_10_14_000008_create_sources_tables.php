<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sources', function (Blueprint $table) {
            $table->id();
            $table->string('title', 500);
            $table->string('author', 500)->nullable();
            $table->string('source_type', 32)->default('book');
            $table->text('publication_details')->nullable();
            $table->string('identifier')->nullable();
            // الرابط الإلكتروني ليس شرطاً لصحة المرجع
            $table->string('url', 2048)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        // يربط المصدر بأي كيان (شخص، كتاب، إجازة، سند، عنصر سند، علاقة شيخ وتلميذ…)
        Schema::create('source_references', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_id')->constrained('sources')->restrictOnDelete();
            $table->morphs('referenceable');
            $table->string('volume', 50)->nullable();
            $table->string('page', 50)->nullable();
            $table->string('manuscript_ref')->nullable();
            $table->text('quote_text')->nullable();
            $table->text('note')->nullable();
            $table->string('reference_status', 32)->default('unverified');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('source_references');
        Schema::dropIfExists('sources');
    }
};
