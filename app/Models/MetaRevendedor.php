<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** Meta mensual de venta pagada de un cliente mayorista. */
class MetaRevendedor extends Model
{
    use BelongsToTenant;

    protected $table = 'metas_revendedor';

    protected $fillable = ['distribuidora_id', 'revendedor_distribuidora_id', 'periodo', 'monto_meta'];

    protected $casts = ['monto_meta' => 'decimal:2'];
}
