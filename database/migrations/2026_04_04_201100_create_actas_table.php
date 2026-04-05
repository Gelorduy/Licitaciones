<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('actas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('tipo');
            $table->date('fecha_registro')->nullable();
            $table->json('apoderados')->nullable();
            $table->json('participacion_accionaria')->nullable();
            $table->string('rpc_folio')->nullable();
            $table->date('rpc_fecha_inscripcion')->nullable();
            $table->string('rpc_lugar')->nullable();
            $table->json('consejo_administracion')->nullable();
            $table->json('direccion_empresa')->nullable();
            $table->string('notaria_numero')->nullable();
            $table->string('notaria_lugar')->nullable();
            $table->string('notario_nombre')->nullable();
            $table->string('escritura_numero')->nullable();
            $table->string('libro_numero')->nullable();
            $table->date('fecha_inscripcion')->nullable();
            $table->string('acto')->nullable();
            $table->string('documento_path')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('actas');
    }
};