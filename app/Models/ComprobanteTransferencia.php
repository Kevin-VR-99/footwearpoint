<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class ComprobanteTransferencia extends Model
{
    use BelongsToTenant;

    protected $table = 'comprobantes_transferencia';

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

    /** TG-411: disco de los comprobantes nuevos (COMPROBANTES_DISK). */
    public static function disco(): string
    {
        return (string) config('comprobantes.disco', 'local');
    }

    /** comprobantes/{distribuidora}/{pedido}/{nombre}, igual en local y en R2. */
    public static function rutaPara(Pedido $pedido, string $nombre): string
    {
        return config('comprobantes.prefijo', 'comprobantes').'/'.$pedido->distribuidora_id.'/'.$pedido->id.'/'.$nombre;
    }

    public function pedido()
    {
        return $this->belongsTo(Pedido::class, 'pedido_id');
    }
}
