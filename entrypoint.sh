#!/bin/bash
echo "Running Laravel migrations..."
php artisan migrate --force

echo "Applying database SQL setup files..."
php -r "require 'vendor/autoload.php'; \$app = require_once 'bootstrap/app.php'; \$kernel = \$app->make(Illuminate\Contracts\Console\Kernel::class); \$kernel->bootstrap(); foreach (glob(database_path('sql/*.sql')) as \$file) { try { Illuminate\Support\Facades\DB::unprepared(file_get_contents(\$file)); echo 'Executed: ' . basename(\$file) . PHP_EOL; } catch (\Exception \$e) { echo 'Skipped/Done: ' . basename(\$file) . PHP_EOL; } }"

echo "Starting Apache server..."
exec apache2-foreground