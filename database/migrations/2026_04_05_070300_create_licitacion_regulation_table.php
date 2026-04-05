<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('licitacion_regulation', function (Blueprint $table) {
            $table->id();
            $table->foreignId('licitacion_id')->constrained('licitaciones')->cascadeOnDelete();
            $table->foreignId('regulation_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['licitacion_id', 'regulation_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('licitacion_regulation');
    }
};
