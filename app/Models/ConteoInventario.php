<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** Sesión de inventario físico. */
class ConteoInventario extends Model
{
    use BelongsToTenant;

    protected $table = 'conteos_inventario';

    protected $fillable = [
        'distribuidora_id', 'sucursal_id', 'producto_id', 'estado', 'creado_por_staff_id',
        'aplicado_por_staff_id', 'aplicado_at', 'notas',
    ];

    protected $casts = ['aplicado_at' => 'datetime'];

    public function renglones()
    {
        return $this->hasMany(ConteoInventarioRenglon::class, 'conteo_id');
    }

    public function producto()
    {
        return $this->belongsTo(Producto::class, 'producto_id');
    }

    public function sucursal()
    {
        return $this->belongsTo(Sucursal::class, 'sucursal_id');
    }

    public function creadoPor()
    {
        return $this->belongsTo(DistribuidoraStaff::class, 'creado_por_staff_id');
    }

    public function estaAbierto(): bool
    {
        return $this->estado === 'abierto';
    }
}
