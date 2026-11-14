<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;

/** A partner system's connection key (only a SHA-256 hash is stored). Created by the Super Admin. */
class IntegrationKey extends Model
{
    protected $table = 'integration_keys';
    protected $primaryKey = 'partner';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;
    protected $guarded = [];

    /** Which partner (HR2 / HR3 / HR4) owns this key, or null. */
    public static function partnerFor(string $key): ?string
    {
        if ($key === '' || strlen($key) > 200) {
            return null;
        }
        $hash = hash('sha256', $key);
        try {
            foreach (static::query()->get(['partner', 'key_hash']) as $row) {
                if (hash_equals((string) $row->key_hash, $hash)) {
                    static::query()->where('partner', $row->partner)->update(['last_used_at' => now()]);

                    return (string) $row->partner;
                }
            }
        } catch (QueryException) {
            // integration_keys table not created yet: only the .env keys below can work.
        }
        foreach ((array) config('hims.partner_keys') as $partner => $envKey) {
            if ($envKey && hash_equals((string) $envKey, $key)) {
                return (string) $partner;
            }
        }

        return null;
    }
}
