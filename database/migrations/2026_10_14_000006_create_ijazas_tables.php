<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ijazas', function (Blueprint $table) {
            $table->id();
            $table->string('ijaza_number', 100)->nullable()->index();
            $table->foreignId('ijaza_type_id')->nullable()->constrained('ijaza_types')->restrictOnDelete();
            $table->foreignId('grantor_person_id')->nullable()->constrained('persons')->restrictOnDelete();
            $table->foreignId('recipient_person_id')->nullable()->constrained('persons')->restrictOnDelete();
            // التاريخ كما كُتب في الوثيقة، مع سنة هجرية وميلادية اختياريتين
            $table->string('ijaza_date_text')->nullable();
            $table->smallInteger('ijaza_year_h')->nullable();
            $table->date('ijaza_date_gregorian')->nullable();
            $table->string('ijaza_place')->nullable();
            // النص الأصلي لا يُعدَّل أبداً؛ التصحيحات في revised_text، والمطبَّع للبحث في text_search
            $table->longText('original_text')->nullable();
            $table->longText('revised_text')->nullable();
            $table->longText('text_search')->nullable();
            $table->text('summary')->nullable();
            $table->text('notes')->nullable();
            $table->string('verification_status', 32)->default('draft');
            $table->string('publication_status', 32)->default('unpublished');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('ijaza_year_h');
            $table->index(['verification_status', 'publication_status'], 'ijazas_status_index');
        });

        Schema::create('ijaza_book', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ijaza_id')->constrained('ijazas')->cascadeOnDelete();
            $table->foreignId('book_id')->constrained('books')->cascadeOnDelete();
            $table->foreignId('book_edition_id')->nullable()->constrained('book_editions')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['ijaza_id', 'book_id'], 'ijaza_book_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ijaza_book');
        Schema::dropIfExists('ijazas');
    }
};
