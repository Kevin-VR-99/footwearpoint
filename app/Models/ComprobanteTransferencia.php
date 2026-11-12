<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class ComprobanteTransferencia extends Model
{
    use BelongsToTenant;

    protected $table = 'comprobantes_transferencia';

    public const DISCO = 'comprobantes';

    /** Tipos que puede subir el cliente. El tope es 5 MB. */
    public const MIMES = ['jpg', 'jpeg', 'png', 'webp', 'pdf'];

    public const TAMANO_MAXIMO_KB = 5120;

    protected $fillable = [
        'distribuidora_id',
        'pedido_id',
        'disco',
        'ruta',
        'nombre_original',
        'mime',
        'tamano',
        'subido_por_usuario_id',
    ];

    public function pedido()
    {
        return $this->belongsTo(Pedido::class, 'pedido_id');
    }
}
