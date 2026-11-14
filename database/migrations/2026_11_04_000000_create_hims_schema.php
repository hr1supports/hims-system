<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HIMS database.
 * - Empty database: builds every HIMS table from database/sql (hostforge-import.sql, then 013 to 025).
 * - Existing HIMS database (hr3_system already has its tables): only adds what is missing
 *   (024 integration tables, 025 shared records). Existing data is not touched.
 * Needs a MySQL user that may CREATE/ALTER tables (for example root), not hr3_app.
 */
return new class extends Migration
{
    private function run(string $file): void
    {
        $sql = (string) file_get_contents(database_path('sql/'.$file));
        DB::unprepared($sql);
    }

    public function up(): void
    {
        if (! Schema::hasTable('users')) {
            $this->run('hostforge-import.sql');
            foreach ([
                '013_remove_shift_scheduling.sql', '014_staff_officer_roles.sql', '015_onboarding.sql',
                '016_superadmin_tools.sql', '017_settings_departments.sql', '018_online_status.sql',
                '019_login_sessions.sql', '020_timekeeping_verification.sql', '021_authenticator.sql',
                '022_vacancy_qualifications.sql', '023_job_boards.sql',
            ] as $file) {
                $this->run($file);
            }
        }
        // Both files only CREATE TABLE IF NOT EXISTS, so they are safe on any HIMS database.
        $this->run('024_file_exchange.sql');
        $this->run('025_shared_records.sql');
    }

    public function down(): void
    {
        // Never drop HIMS data automatically.
    }
};
