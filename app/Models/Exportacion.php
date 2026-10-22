<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Exportación pesada (reporte PDF/Excel, listado de pedidos) que se arma en
 * la cola. El archivo vive en el disco privado config('exportaciones.disco')
 * y solo lo descarga quien la pidió, con un enlace firmado y temporal.
 */
class Exportacion extends Model
{
    use BelongsToTenant;

    public const PENDIENTE = 'pendiente';
    public const PROCESANDO = 'procesando';
    public const LISTA = 'lista';
    public const FALLIDA = 'fallida';

    protected $table = 'exportaciones';

    protected $fillable = [
        'distribuidora_id', 'usuario_id', 'tipo', 'formato', 'filtros', 'estado',
        'disco', 'ruta', 'nombre_archivo', 'bytes', 'error', 'expira_at',
    ];

    protected $casts = [
        'filtros' => 'array',
        'expira_at' => 'datetime',
    ];

    public function usuario()
    {
        return $this->belongsTo(Usuario::class, 'usuario_id');
    }

    public function disponible(): bool
    {
        return $this->estado === self::LISTA && $this->ruta !== null
            && ($this->expira_at === null || $this->expira_at->isFuture());
    }
}
