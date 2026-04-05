<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('licitaciones', function (Blueprint $table) {
            $table->foreignId('company_letterhead_id')->nullable()->after('company_id')->constrained('company_letterheads')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('licitaciones', function (Blueprint $table) {
            $table->dropConstrainedForeignId('company_letterhead_id');
        });
    }
};
