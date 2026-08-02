<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Color extends Model
{
    protected $table = 'colores';

    public $timestamps = false;

    protected $fillable = [
        'nombre',
        'codigo_hex',
        'activo',
    ];

    protected $casts = [
        'activo' => 'boolean',
    ];
}