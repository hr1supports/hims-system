<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One delivery from HIMS to a partner system (pending, sent or failed). */
class IntegrationOutbox extends Model
{
    protected $table = 'integration_outbox';
    public $timestamps = false;
    protected $guarded = [];
    protected $casts = ['attempts' => 'integer', 'response_code' => 'integer'];
}
