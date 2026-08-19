<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Export extends Model
{
    protected $table = 'exports';

    protected $guarded = ['id'];

    protected $casts = [
        'date_export' => 'datetime',
    ];

    public $timestamps = true;
}