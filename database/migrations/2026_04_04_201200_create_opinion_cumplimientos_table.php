<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('opinion_cumplimientos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->enum('tipo', ['sat', 'infonavit', 'imss']);
            $table->enum('estado', ['positivo', 'negativo', 'pendiente']);
            $table->date('fecha_emision');
            $table->date('fecha_vigencia')->nullable();
            $table->date('vigencia_calculada')->storedAs("IFNULL(fecha_vigencia, DATE_ADD(fecha_emision, INTERVAL 30 DAY))");
            $table->string('documento_path')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('opinion_cumplimientos');
    }
};