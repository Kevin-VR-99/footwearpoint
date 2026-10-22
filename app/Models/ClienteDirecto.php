<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\BelongsToTenant;

class ClienteDirecto extends Model
{
    use BelongsToTenant;
    use \App\Models\Concerns\RevocaAccesoAlDesactivar;
    protected $table = 'clientes_directos';

    protected $fillable = [
        'distribuidora_id',
        'usuario_id',
        'nombre',
        'telefono',
        'email',
        'direccion_contacto',
        'notas',
        'estado',
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
