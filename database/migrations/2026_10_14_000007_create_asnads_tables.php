<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * السند قائمة مرتبة من العناصر. sequence_number = 1 هو الطرف الأقرب إلينا (الشيخ الحالي)،
 * وآخر رقم هو الأقدم. الأعداد (الرجال، الوسائط، الفريدون، الظهور) تُحسب عند الطلب ولا تُخزَّن.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asnads', function (Blueprint $table) {
            $table->id();
            $table->string('title', 500)->nullable();
            $table->foreignId('sanad_type_id')->nullable()->constrained('sanad_types')->restrictOnDelete();
            $table->longText('chain_text_original')->nullable();
            $table->longText('chain_text_search')->nullable();
            // حدّا العدّ: فارغان يعني أول عنصر وآخر عنصر. يُحدَّدان صراحة للسند الذي لا ينتهي بمؤلف.
            $table->unsignedSmallInteger('count_from_seq')->nullable();
            $table->unsignedSmallInteger('count_to_seq')->nullable();
            $table->text('notes')->nullable();
            $table->string('verification_status', 32)->default('draft');
            $table->string('publication_status', 32)->default('unpublished');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['verification_status', 'publication_status'], 'asnads_status_index');
        });

        Schema::create('book_asnad', function (Blueprint $table) {
            $table->id();
            $table->foreignId('book_id')->constrained('books')->cascadeOnDelete();
            $table->foreignId('asnad_id')->constrained('asnads')->cascadeOnDelete();
            $table->foreignId('book_edition_id')->nullable()->constrained('book_editions')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['book_id', 'asnad_id'], 'book_asnad_unique');
        });

        Schema::create('ijaza_asnad', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ijaza_id')->constrained('ijazas')->cascadeOnDelete();
            $table->foreignId('asnad_id')->constrained('asnads')->cascadeOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['ijaza_id', 'asnad_id'], 'ijaza_asnad_unique');
        });

        Schema::create('asnad_nodes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asnad_id')->constrained('asnads')->cascadeOnDelete();
            $table->unsignedSmallInteger('sequence_number');
            // بلا on delete صريح (المنع افتراضياً): لا يُحذف شخص وله سندات. العمود في قيد CHECK.
            $table->foreignId('person_id')->nullable()->constrained('persons');
            // للعنصر غير المحقق الذي لم يُربط بشخص بعد
            $table->string('unresolved_name', 500)->nullable();
            $table->text('text_segment')->nullable();
            $table->foreignId('chain_role_id')->nullable()->constrained('chain_roles')->restrictOnDelete();
            $table->foreignId('transmission_mode_id')->nullable()->constrained('transmission_modes')->restrictOnDelete();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // الشخص قد يتكرر داخل السند نفسه، فلا فرادة على person_id
            $table->unique(['asnad_id', 'sequence_number'], 'asnad_nodes_sequence_unique');
            $table->index(['asnad_id', 'person_id'], 'asnad_nodes_asnad_person_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asnad_nodes');
        Schema::dropIfExists('ijaza_asnad');
        Schema::dropIfExists('book_asnad');
        Schema::dropIfExists('asnads');
    }
};
