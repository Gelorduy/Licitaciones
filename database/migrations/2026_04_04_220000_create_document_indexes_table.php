<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_indexes', function (Blueprint $table) {
            $table->id();
            $table->morphs('documentable');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('document_type');
            $table->string('storage_disk')->default('s3');
            $table->string('storage_path');
            $table->string('extraction_method')->nullable();
            $table->longText('extracted_text')->nullable();
            $table->json('metadata')->nullable();
            $table->unsignedInteger('chunk_count')->default(0);
            $table->timestamp('indexed_at')->nullable();
            $table->enum('status', ['pending', 'processed', 'indexed', 'failed'])->default('pending');
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->unique(['documentable_type', 'documentable_id'], 'document_indexes_documentable_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_indexes');
    }
};
