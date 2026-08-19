<?php

namespace App\Models;

use App\Support\BelgiumGeography;
use Illuminate\Database\Eloquent\Model;

class Company extends Model
{
    protected $table = 'companies';

    protected $guarded = ['id'];

    protected $casts = [
        'horaires' => 'array',
        'enrichissement' => 'array',
        'date_import' => 'datetime',
        'derniere_verification' => 'datetime',
        'enrichi_le' => 'datetime',
        'geocode_le' => 'datetime',
        'es_synchronise_le' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::saving(function (Company $company) {
            $company->region = BelgiumGeography::regionForProvince($company->province);
        });
    }
}