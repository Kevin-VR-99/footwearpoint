<?php

namespace App\Services\Perfil;

use App\Models\Usuario;

/**
 * Editar el propio nombre y teléfono (E1-05 / TG-110, reescrito en TG-216).
 *
 * Ya no hay nada que sincronizar: el nombre y el teléfono de un revendedor o
 * de un cliente directo viven SOLO en su registro de contacto, que es el mismo
 * que edita el panel. Antes se guardaban en dos lugares y había que copiarlos
 * de uno a otro; ahora el perfil de la app y la pantalla del panel escriben en
 * el mismo renglón.
 *
 * El personal (admins y empleados) no tiene registro de contacto: sus datos
 * siguen en su cuenta.
 *
 * Un cliente directo que le compra a dos distribuidoras edita el registro de
 * la distribuidora con la que entró, que es la que resuelve Tenant y la misma
 * cuyo catálogo está viendo.
 */
class ActualizarPerfilAction
{
    public function ejecutar(Usuario $usuario, string $nombre, ?string $telefono): Usuario
    {
        $datos = [
            'nombre' => $nombre,
            'telefono' => $telefono,
        ];

        $contacto = $usuario->contacto();

        if ($contacto) {
            $contacto->update($datos);

            return $usuario->refresh();
        }

        $usuario->update($datos);

        return $usuario->refresh();
    }
}
