<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Where HIMS sends a partner system's data (their URL and their key, encrypted). */
class IntegrationEndpoint extends Model
{
    protected $table = 'integration_endpoints';
    protected $primaryKey = 'partner';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;
    protected $guarded = [];
    protected $hidden = ['token_encrypted'];
    protected $casts = ['enabled' => 'boolean'];
}
