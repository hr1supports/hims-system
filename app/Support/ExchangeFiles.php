<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/** File checks and storage for File Exchange (same rules as the HIMS File Exchange page). */
class ExchangeFiles
{
    public const TYPES = [
        'pdf' => 'application/pdf',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'ppt' => 'application/vnd.ms-powerpoint',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'csv' => 'text/csv',
        'txt' => 'text/plain',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'zip' => 'application/zip',
    ];

    public static function dir(): string
    {
        $dir = (string) config('hims.exchange_dir');
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        return $dir;
    }

    /** Checks the real file header (not just the extension) and saves it. Returns the details to store. */
    public static function store(UploadedFile $file): array
    {
        $original = mb_substr((string) preg_replace('/[\x00-\x1F\x7F]/u', '', basename(str_replace('\\', '/', $file->getClientOriginalName()))), 0, 200);
        $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
        if ($original === '' || ! isset(self::TYPES[$ext]) || ! self::contentOk($ext, $file->getRealPath())) {
            throw ValidationException::withMessages([
                'file' => 'Only real PDF, Word, Excel, PowerPoint, CSV, TXT, JPG, PNG or ZIP files can be sent.',
            ]);
        }
        $stored = bin2hex(random_bytes(16)).'.'.$ext;
        $file->move(self::dir(), $stored);
        $path = self::dir().DIRECTORY_SEPARATOR.$stored;

        return [
            'original_name' => $original,
            'stored_name' => $stored,
            'mime_type' => self::TYPES[$ext],
            'file_size' => (int) filesize($path),
            'sha256' => hash_file('sha256', $path),
        ];
    }

    public static function contentOk(string $ext, string $path): bool
    {
        $head = (string) file_get_contents($path, false, null, 0, 8192);

        return match ($ext) {
            'pdf' => str_starts_with($head, '%PDF'),
            'png' => str_starts_with($head, "\x89PNG"),
            'jpg', 'jpeg' => str_starts_with($head, "\xFF\xD8\xFF"),
            'doc', 'xls', 'ppt' => str_starts_with($head, "\xD0\xCF\x11\xE0"),
            'docx', 'xlsx', 'pptx', 'zip' => str_starts_with($head, "PK\x03\x04"),
            'csv', 'txt' => ! str_contains($head, "\x00"),
            default => false,
        };
    }

    public static function path(string $stored): ?string
    {
        if (! preg_match('/^[a-f0-9]{32}\.[a-z]{3,4}$/', $stored)) {
            return null;
        }
        $path = self::dir().DIRECTORY_SEPARATOR.$stored;

        return is_file($path) ? $path : null;
    }
}
