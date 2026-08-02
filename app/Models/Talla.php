<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Talla extends Model
{
    protected $table = 'tallas';

    public $timestamps = false;

    protected $fillable = [
        'sistema',
        'valor',
        'orden',
        'activa',
    ];

    protected $casts = [
        'activa' => 'boolean',
    ];
}