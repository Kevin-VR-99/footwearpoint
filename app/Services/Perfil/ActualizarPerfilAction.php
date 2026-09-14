<?php

namespace App\Services\Perfil;

use App\Models\ClienteDirecto;
use App\Models\Revendedor;
use App\Models\Usuario;
use Illuminate\Support\Facades\DB;

/**
 * Editar nombre y teléfono del propio usuario (E1-05 / TG-110).
 *
 * El nombre y el teléfono de un revendedor o cliente directo viven en DOS
 * lugares: en usuarios (su cuenta de la app) y en su registro de contacto
 * (revendedores o clientes_directos), que es el que ve el empleado en el
 * panel web. E3-07 los copia de uno a otro al crear la cuenta.
 *
 * Decisión del equipo: al editar desde la app se actualizan los dos a la vez,
 * en la misma transacción, para que nunca se desincronicen.
 */
class ActualizarPerfilAction
{
    public function ejecutar(Usuario $usuario, string $nombre, ?string $telefono): Usuario
    {
        $datos = [
            'nombre'   => $nombre,
            'telefono' => $telefono,
        ];

        return DB::transaction(function () use ($usuario, $datos) {
            $usuario->update($datos);

            // Se busca por usuario_id SIN el TenantScope (withoutGlobalScopes),
            // igual que en App\Support\Tenant: así también se actualiza el
            // registro de alguien con la afiliación suspendida, cuyo tenant
            // sale null. Es seguro porque el filtro es su propio usuario_id,
            // que es único en las dos tablas: solo toca su propio registro.
            Revendedor::withoutGlobalScopes()
                ->where('usuario_id', $usuario->id)
                ->update($datos);

            ClienteDirecto::withoutGlobalScopes()
                ->where('usuario_id', $usuario->id)
                ->update($datos);

            return $usuario->refresh();
        });
    }
}
