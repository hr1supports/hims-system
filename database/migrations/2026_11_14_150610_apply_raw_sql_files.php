<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $sqlFiles = [
            database_path('sql/001_hiring.sql'),
            database_path('sql/002_security.sql'),
            database_path('sql/003_archive.sql'),
            database_path('sql/004_archive_vacancies.sql'),
            database_path('sql/005_super_admin.sql'),
            database_path('sql/006_admin_password.sql'),
        ];

        foreach ($sqlFiles as $file) {
            if (file_exists($file)) {
                try {
                    DB::unprepared(file_get_contents($file));
                } catch (\Exception $e) {
                    // Skip if statements have already been run
                }
            }
        }
    }

    public function down(): void
    {
        //
    }
};