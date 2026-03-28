<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RegistrationPhoneCountry extends Model
{
    protected $table = 'registration_phone_countries';

    protected $fillable = [
        'iso2',
        'name',
        'is_enabled',
        'sort_order',
    ];

    protected $casts = [
        'is_enabled' => 'boolean',
    ];
}
