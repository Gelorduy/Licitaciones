<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proposal_validation_findings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('proposal_validation_id')->constrained('proposal_validations')->cascadeOnDelete();
            $table->enum('severity', ['critical', 'warning', 'info']);
            $table->string('category');
            $table->string('rule_code');
            $table->text('message');
            $table->enum('status', ['open', 'accepted', 'resolved', 'waived'])->default('open');
            $table->text('resolution_note')->nullable();
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['proposal_validation_id', 'severity'], 'pvf_validation_severity_idx');
            $table->index(['proposal_validation_id', 'status'], 'pvf_validation_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proposal_validation_findings');
    }
};
