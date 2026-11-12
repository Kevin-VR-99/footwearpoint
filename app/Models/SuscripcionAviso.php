<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** Un aviso de vencimiento ya reservado para un día. No se vuelve a mandar. */
class SuscripcionAviso extends Model
{
    use BelongsToTenant;

    public const POR_VENCER_3 = 'por_vencer_3';

    public const POR_VENCER_1 = 'por_vencer_1';

    public const VENCE_HOY = 'vence_hoy';

    public const GRACIA = 'gracia';

    public const SUSPENDIDA = 'suspendida';

    public const SIGUE_VENCIDA = 'sigue_vencida';

    protected $table = 'suscripcion_avisos';

    const UPDATED_AT = null;

    protected $fillable = [
        'distribuidora_id',
        'suscripcion_id',
        'tipo',
        'fecha',
    ];

    protected $casts = [
        'fecha' => 'date',
    ];
}
