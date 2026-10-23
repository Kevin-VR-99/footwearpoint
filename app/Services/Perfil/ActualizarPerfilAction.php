<?php

namespace App\Services\Perfil;

use App\Models\Revendedor;
use App\Models\Usuario;
use Illuminate\Validation\ValidationException;

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
    /**
     * $cambiarTelefono en false deja el teléfono como está (la app ya no lo
     * manda). Un cliente mayorista no cambia su teléfono aquí: desde ahora
     * se confirma por correo (CambioContactoAction).
     */
    public function ejecutar(Usuario $usuario, string $nombre, ?string $telefono, bool $cambiarTelefono = true): Usuario
    {
        $datos = ['nombre' => $nombre];

        $contacto = $usuario->contacto();

        if ($cambiarTelefono) {
            $actual = $contacto ? $contacto->telefono : $usuario->telefono;

            if ($contacto instanceof Revendedor && (string) $telefono !== (string) $actual) {
                throw ValidationException::withMessages([
                    'telefono' => [CambioContactoAction::MENSAJE_USA_CONFIRMACION],
                ]);
            }

            $datos['telefono'] = $telefono;
        }

        if ($contacto) {
            $contacto->update($datos);

            return $usuario->refresh();
        }

        $usuario->update($datos);

        return $usuario->refresh();
    }
}
