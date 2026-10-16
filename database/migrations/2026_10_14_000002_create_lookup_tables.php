<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * جداول التصنيفات القابلة للإدارة من لوحة التحكم دون تعديل الكود.
 */
return new class extends Migration
{
    /** @var list<string> */
    private array $simpleLookups = ['ijaza_types', 'sanad_types', 'chain_roles', 'transmission_modes'];

    public function up(): void
    {
        foreach ($this->simpleLookups as $name) {
            Schema::create($name, function (Blueprint $table) {
                $table->id();
                $table->string('name')->unique();
                $table->text('description')->nullable();
                $table->boolean('is_active')->default(true);
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->timestamps();
            });
        }

        Schema::create('subject_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('subject_categories')->restrictOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index('name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subject_categories');

        foreach (array_reverse($this->simpleLookups) as $name) {
            Schema::dropIfExists($name);
        }
    }
};
