<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** Una variante dentro de un conteo: esperado contra contado. */
class ConteoInventarioRenglon extends Model
{
    use BelongsToTenant;

    protected $table = 'conteos_inventario_renglones';

    protected $fillable = ['distribuidora_id', 'conteo_id', 'variante_id', 'esperado', 'contado', 'ajuste_aplicado'];

    protected $casts = ['esperado' => 'integer', 'contado' => 'integer', 'ajuste_aplicado' => 'integer'];

    public function conteo()
    {
        return $this->belongsTo(ConteoInventario::class, 'conteo_id');
    }

    public function variante()
    {
        return $this->belongsTo(Variante::class, 'variante_id');
    }

    public function diferencia(): ?int
    {
        return $this->contado === null ? null : $this->contado - $this->esperado;
    }
}
