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
