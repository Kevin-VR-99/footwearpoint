<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\MuestraCorreoDeCuenta;
use App\Models\Concerns\RevocaAccesoAlDesactivar;
use Illuminate\Database\Eloquent\Model;

class ClienteDirecto extends Model
{
    use BelongsToTenant;
    use MuestraCorreoDeCuenta;
    use RevocaAccesoAlDesactivar;

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
