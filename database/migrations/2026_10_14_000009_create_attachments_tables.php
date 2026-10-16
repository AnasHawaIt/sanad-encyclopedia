<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attachments', function (Blueprint $table) {
            $table->id();
            $table->string('original_filename');
            // اسم التخزين مولَّد من الخادم ولا يعتمد على اسم الملف المرسل
            $table->string('stored_path', 500);
            $table->string('disk', 32)->default('local');
            $table->string('mime_type', 150);
            $table->unsignedBigInteger('file_size');
            $table->char('checksum', 64);
            $table->text('description')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('checksum');
        });

        Schema::create('attachables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attachment_id')->constrained('attachments')->cascadeOnDelete();
            $table->morphs('attachable');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['attachment_id', 'attachable_type', 'attachable_id'], 'attachables_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attachables');
        Schema::dropIfExists('attachments');
    }
};
