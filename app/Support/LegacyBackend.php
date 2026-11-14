<?php

namespace App\Support;

/**
 * Prepares the environment for the HIMS backend in app/Legacy/backend.
 *
 * That code is the same PHP the HIMS pages used before Laravel (login, OTP, accounts, hiring,
 * timekeeping, file exchange, claims...). Laravel now hosts it: it reads its database settings
 * and folders from Laravel's .env / config, and keeps its own login session (cookie HR3SESSID).
 */
class LegacyBackend
{
    public static function path(string $file = ''): string
    {
        return app_path('Legacy/backend'.($file !== '' ? DIRECTORY_SEPARATOR.$file : ''));
    }

    public static function boot(): void
    {
        // Database: reuse Laravel's .env values unless HR3_* variables are set explicitly.
        $db = config('database.connections.'.config('database.default'), []);
        self::setIfEmpty('HR3_DB_HOST', $db['host'] ?? '127.0.0.1');
        self::setIfEmpty('HR3_DB_PORT', (string) ($db['port'] ?? '3306'));
        self::setIfEmpty('HR3_DB_NAME', $db['database'] ?? 'hr3_system');
        self::setIfEmpty('HR3_DB_USER', $db['username'] ?? 'hr3_app');
        self::setIfEmpty('HR3_DB_PASSWORD', (string) ($db['password'] ?? ''));
        self::setIfEmpty('HR3_TIMEZONE', (string) config('hims.timezone'));

        // Upload folders (created if missing), shared with the Laravel API controllers.
        foreach (['HR3_UPLOAD_DIR' => 'resume_dir', 'HIMS_EXCHANGE_DIR' => 'exchange_dir', 'HIMS_RECEIPT_DIR' => 'receipt_dir'] as $env => $key) {
            $dir = (string) config('hims.'.$key);
            if (! is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
            self::set($env, $dir);
        }
        foreach ((array) config('hims.partner_keys') as $partner => $key) {
            if ($key) {
                self::setIfEmpty($partner.'_INTEGRATION_KEY', (string) $key);
            }
        }

        // The old code expects PHP's normal warning handling (logged, not shown), not Laravel's
        // "every warning is an exception" mode.
        set_error_handler(static fn () => false);
        ini_set('display_errors', '0');
    }

    private static function setIfEmpty(string $name, string $value): void
    {
        if ((string) getenv($name) === '') {
            self::set($name, $value);
        }
    }

    private static function set(string $name, string $value): void
    {
        putenv($name.'='.$value);
        $_ENV[$name] = $value;
    }
}
