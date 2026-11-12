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

    protected $casts = [
        'telefono_por_recapturar' => 'boolean',
    ];

    protected $fillable = [
        'distribuidora_id',
        'usuario_id',
        'nombre',
        'telefono',
        'telefono_por_recapturar',
        'email',
        'foto_url',
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

    public static function debeRecapturarTelefono(?\App\Models\Usuario $usuario): bool
    {
        if ($usuario === null) {
            return false;
        }

        $distribuidoraId = \App\Support\Tenant::paraUsuario((int) $usuario->id) ?? \App\Support\Tenant::id();

        if ($distribuidoraId === null) {
            return false;
        }

        return static::withoutGlobalScopes()
            ->where('usuario_id', $usuario->id)
            ->where('distribuidora_id', $distribuidoraId)
            ->where('telefono_por_recapturar', true)
            ->exists();
    }

    public function usuarioIdParaAcceso(): ?int
    {
        $id = $this->usuario_id;

        return $id !== null ? (int) $id : null;
    }
}
