<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('books', function (Blueprint $table) {
            $table->id();
            $table->string('title', 500);
            $table->string('title_search', 500)->nullable();
            // قد يكون المؤلف غير معروف؛ لا يُنسب كتاب إلى مؤلف دون توثيق
            $table->foreignId('author_person_id')->nullable()->constrained('persons')->restrictOnDelete();
            $table->foreignId('subject_category_id')->nullable()->constrained('subject_categories')->restrictOnDelete();
            $table->text('summary')->nullable();
            $table->longText('description')->nullable();
            $table->text('notes')->nullable();
            $table->string('verification_status', 32)->default('draft');
            $table->string('publication_status', 32)->default('unpublished');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('title_search');
            $table->index(['verification_status', 'publication_status'], 'books_status_index');
        });

        Schema::create('book_titles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('book_id')->constrained('books')->cascadeOnDelete();
            $table->string('title', 500);
            $table->string('title_search', 500)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('title_search');
        });

        // الشارح والمختصِر والجامع والمحقق ونحوهم، بجانب المؤلف الأساسي في books
        Schema::create('book_contributors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('book_id')->constrained('books')->cascadeOnDelete();
            $table->foreignId('person_id')->constrained('persons')->restrictOnDelete();
            $table->string('role', 32)->default('contributor');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['book_id', 'person_id', 'role'], 'book_contributors_unique');
        });

        Schema::create('book_editions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('book_id')->constrained('books')->cascadeOnDelete();
            $table->string('edition_type', 32)->default('printed');
            $table->string('publisher')->nullable();
            $table->foreignId('editor_person_id')->nullable()->constrained('persons')->restrictOnDelete();
            $table->string('editor_name')->nullable();
            $table->smallInteger('publication_year')->nullable();
            $table->unsignedSmallInteger('volumes_count')->nullable();
            $table->string('manuscript_ref')->nullable();
            $table->string('library_name')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('book_editions');
        Schema::dropIfExists('book_contributors');
        Schema::dropIfExists('book_titles');
        Schema::dropIfExists('books');
    }
};
