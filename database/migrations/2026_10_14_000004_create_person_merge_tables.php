<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * الدمج لا يتم إلا بطلب وموافقة صريحة، ويُسجَّل كل صف أُعيد توجيهه ليكون التراجع ممكناً بدقة.
 */
return new class extends Migration
{
    public function up(): void
    {
        // اقتراحات تشابه فقط: لا تغيّر أي سجل. الترتيب القانوني person_a_id < person_b_id يمنع تكرار الزوج.
        Schema::create('person_merge_suggestions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('person_a_id')->constrained('persons');
            $table->foreignId('person_b_id')->constrained('persons');
            $table->decimal('score', 5, 2)->nullable();
            $table->json('reasons')->nullable();
            $table->string('status', 32)->default('pending');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->unique(['person_a_id', 'person_b_id'], 'merge_suggestions_pair_unique');
            $table->index('status');
        });

        Schema::create('person_merge_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('primary_person_id')->constrained('persons');
            $table->foreignId('secondary_person_id')->constrained('persons');
            $table->string('status', 32)->default('pending');
            $table->text('reason');
            $table->foreignId('requested_by')->constrained('users');
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_notes')->nullable();
            $table->timestamp('executed_at')->nullable();
            $table->timestamp('reverted_at')->nullable();
            $table->json('snapshot')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index(['primary_person_id', 'secondary_person_id'], 'merge_requests_pair_index');
        });

        Schema::create('person_merge_repoints', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merge_request_id')->constrained('person_merge_requests')->cascadeOnDelete();
            $table->string('table_name', 64);
            $table->unsignedBigInteger('row_id');
            $table->string('column_name', 64);
            $table->unsignedBigInteger('old_person_id');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['table_name', 'row_id'], 'merge_repoints_row_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('person_merge_repoints');
        Schema::dropIfExists('person_merge_requests');
        Schema::dropIfExists('person_merge_suggestions');
    }
};
