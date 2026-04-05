<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('actas', function (Blueprint $table) {
            $table->string('documento_original_name')->nullable()->after('documento_path');
        });

        Schema::table('opinion_cumplimientos', function (Blueprint $table) {
            $table->string('documento_original_name')->nullable()->after('documento_path');
        });
    }

    public function down(): void
    {
        Schema::table('actas', function (Blueprint $table) {
            $table->dropColumn('documento_original_name');
        });

        Schema::table('opinion_cumplimientos', function (Blueprint $table) {
            $table->dropColumn('documento_original_name');
        });
    }
};
