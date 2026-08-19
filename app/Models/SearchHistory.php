<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SearchHistory extends Model
{
    protected $table = 'search_history';

    protected $guarded = ['id'];

    protected $casts = [
        'date' => 'datetime',
    ];

    public $timestamps = true;
}