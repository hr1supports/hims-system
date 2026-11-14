<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A file HIMS received from (incoming) or sent to (outgoing) HR2 / HR3 / HR4. */
class FileExchange extends Model
{
    protected $table = 'file_exchange';
    public $timestamps = false;
    protected $guarded = [];
    protected $casts = ['archived' => 'boolean', 'file_size' => 'integer'];

    public function sender()
    {
        return $this->belongsTo(User::class, 'sent_by');
    }
}
