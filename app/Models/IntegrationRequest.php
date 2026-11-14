<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A request sent by HR2 / HR3 / HR4, approved or rejected by an HR Officer in HIMS. */
class IntegrationRequest extends Model
{
    protected $table = 'integration_requests';
    public $timestamps = false;
    protected $guarded = [];
    protected $casts = ['hours' => 'float'];
}
