<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A job opening. Hire orders from HR3 arrive as Pending; an HR Officer publishes them (Open). */
class Vacancy extends Model
{
    protected $table = 'vacancies';
    public $timestamps = false;
    protected $guarded = [];

    public const TYPES = ['Full-time', 'Part-time', 'Contract', 'Trainee'];
}
