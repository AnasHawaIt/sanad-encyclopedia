<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * الأشخاص سجلات مستقلة، واسم الشخص غير فريد عمداً: تشابه الأسماء لا يعني اتحاد أصحابها.
 * الأعمدة *_search مطبَّعة لأغراض البحث فقط، والنص الأصلي لا يُمس.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('persons', function (Blueprint $table) {
            $table->id();
            $table->string('primary_name');
            $table->string('full_name', 500)->nullable();
            $table->string('kunyah')->nullable();
            $table->string('nisbah')->nullable();
            $table->string('name_search', 500)->nullable();
            // القول المعتمد فقط؛ أقوال المصادر المتعددة في person_date_claims
            $table->smallInteger('birth_year_h')->nullable();
            $table->smallInteger('death_year_h')->nullable();
            $table->string('country_or_region')->nullable();
            $table->longText('biography')->nullable();
            $table->text('notes')->nullable();
            $table->string('verification_status', 32)->default('draft');
            $table->string('publication_status', 32)->default('unpublished');
            // يُملأ عند الدمج المعتمد فقط؛ لا حذف ولا دمج تلقائي
            $table->foreignId('merged_into_person_id')->nullable()->constrained('persons')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('primary_name');
            $table->index('name_search');
            $table->index('death_year_h');
            $table->index(['verification_status', 'publication_status'], 'persons_status_index');
        });

        Schema::create('person_name_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('person_id')->constrained('persons')->cascadeOnDelete();
            $table->string('name');
            $table->string('name_search', 500)->nullable();
            $table->string('variant_type', 32)->default('name');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('name_search');
        });

        Schema::create('person_date_claims', function (Blueprint $table) {
            $table->id();
            $table->foreignId('person_id')->constrained('persons')->cascadeOnDelete();
            $table->string('claim_type', 16);
            $table->smallInteger('year');
            $table->string('calendar', 16)->default('hijri');
            $table->string('certainty', 16)->default('certain');
            $table->boolean('is_preferred')->default(false);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['person_id', 'claim_type']);
        });

        // علاقة شيخ وتلميذ: غير فريدة عمداً، فقد تتعدد صور الأخذ بين الشخصين نفسيهما
        Schema::create('person_relations', function (Blueprint $table) {
            $table->id();
            // بلا on delete صريح: السلوك الافتراضي في InnoDB هو المنع، وهذان العمودان في قيد CHECK
            $table->foreignId('teacher_person_id')->constrained('persons');
            $table->foreignId('student_person_id')->constrained('persons');
            $table->string('relation_type', 32)->default('unspecified');
            $table->string('verification_status', 32)->default('draft');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['teacher_person_id', 'student_person_id'], 'person_relations_pair_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('person_relations');
        Schema::dropIfExists('person_date_claims');
        Schema::dropIfExists('person_name_variants');
        Schema::dropIfExists('persons');
    }
};
