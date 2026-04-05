<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proposal_validations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('licitacion_id')->constrained('licitaciones')->cascadeOnDelete();
            $table->enum('status', ['draft', 'reviewed', 'ready_for_export'])->default('draft');
            $table->enum('traffic_light', ['green', 'yellow', 'red'])->default('red');
            $table->json('report')->nullable();
            $table->boolean('override_applied')->default(false);
            $table->text('override_reason')->nullable();
            $table->timestamp('audited_at')->nullable();
            $table->timestamp('ready_at')->nullable();
            $table->timestamps();

            $table->unique('licitacion_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proposal_validations');
    }
};
