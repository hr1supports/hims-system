<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** HIMS pushes data to HR2 / HR3 / HR4 / Administrators (database/sql/026_partner_push.sql). Safe to run again. */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared((string) file_get_contents(database_path('sql/026_partner_push.sql')));
    }

    public function down(): void
    {
        // Keep integration data.
    }
};
