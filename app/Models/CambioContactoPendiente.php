<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Cambio de correo o teléfono pedido desde la app que espera confirmación
 * por correo. Global (sin distribuidora): pertenece a la cuenta.
 */
class CambioContactoPendiente extends Model
{
    protected $table = 'cambios_contacto_pendientes';

    protected $fillable = [
        'usuario_id',
        'tipo',
        'valor_anterior',
        'valor_nuevo',
        'expira_en',
        'confirmado_en',
    ];

    protected $casts = [
        'expira_en'     => 'datetime',
        'confirmado_en' => 'datetime',
    ];

    public function usuario()
    {
        return $this->belongsTo(Usuario::class, 'usuario_id');
    }

    public function vigente(): bool
    {
        return $this->confirmado_en === null && $this->expira_en->isFuture();
    }
}
