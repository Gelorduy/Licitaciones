<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('licitaciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->enum('process_type', ['publica', 'privada']);
            $table->string('legal_signer_name')->nullable();
            $table->enum('status', ['draft', 'analyzing', 'ready'])->default('draft');
            $table->string('bases_document_path')->nullable();
            $table->string('bases_document_original_name')->nullable();
            $table->json('checklist')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('licitaciones');
    }
};
