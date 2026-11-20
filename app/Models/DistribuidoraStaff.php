<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\BelongsToTenant;

class DistribuidoraStaff extends Model
{
    use BelongsToTenant;
    use \App\Models\Concerns\RevocaAccesoAlDesactivar;
    protected $table = 'distribuidora_staff';

    protected $fillable = [
        'distribuidora_id',
        'usuario_id',
        'tipo',
        'estado',
        'fecha_alta',
        'permisos_finos',
    ];

    protected $casts = [
        'fecha_alta' => 'datetime',
        'permisos_finos' => 'array',
    ];

    /**
     * TG-338 (K40): administradores que de verdad pueden actuar. Desactivar es
     * la cuenta completa (usuarios.estado) y no toca esta fila, así que mirar
     * solo distribuidora_staff.estado seguiría avisándole a una cuenta apagada.
     */
    public function scopeAdministradoresActivos($consulta)
    {
        return $consulta
            ->where('distribuidora_staff.tipo', 'administrador')
            ->where('distribuidora_staff.estado', 'activo')
            ->whereIn('distribuidora_staff.usuario_id', Usuario::query()->select('id')->where('estado', 'activo'));
    }

    public function distribuidora()
    {
        return $this->belongsTo(Distribuidora::class, 'distribuidora_id');
    }

    public function usuario()
    {
        return $this->belongsTo(Usuario::class, 'usuario_id');
    }

    public function usuarioIdParaAcceso(): ?int
    {
        $id = $this->usuario_id;

        return $id !== null ? (int) $id : null;
    }
}
