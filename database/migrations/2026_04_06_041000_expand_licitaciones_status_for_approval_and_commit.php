<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE licitaciones MODIFY COLUMN status ENUM('draft','analyzing','ready','sent_for_approval','committed') NOT NULL DEFAULT 'draft'");
    }

    public function down(): void
    {
        DB::statement("UPDATE licitaciones SET status = 'ready' WHERE status IN ('sent_for_approval','committed')");
        DB::statement("ALTER TABLE licitaciones MODIFY COLUMN status ENUM('draft','analyzing','ready') NOT NULL DEFAULT 'draft'");
    }
};
