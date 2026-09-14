<?php

namespace App\Services\Distribuidora;

use App\Models\Usuario;

/**
 * TG-147 — Sincronización panel → app.
 *
 * Decisión del equipo: nombre y teléfono deben quedar iguales en la cuenta
 * (usuarios) y en el registro de contacto (revendedores / clientes_directos),
 * sin importar desde dónde se editen.
 *
 * El sentido app → panel ya lo hace ActualizarPerfilAction (TG-110). Esta
 * clase es el otro sentido: cuando el admin corrige los datos desde la
 * pantalla Configuración o desde la API del panel, se actualiza también la
 * cuenta, para que la app no siga mostrando el dato viejo.
 *
 * Solo nombre y teléfono. El correo NO se sincroniza a propósito: el de
 * contacto y el de la cuenta pueden ser distintos.
 */
class SincronizarCuentaDesdeContactoAction
{
    private const CAMPOS = ['nombre', 'telefono'];

    /**
     * Mismo criterio que el perfil de la app (ActualizarPerfilRequest): un
     * teléfono vacío se guarda como "sin teléfono", en los dos lugares.
     */
    public function normalizar(array $datos): array
    {
        if (array_key_exists('telefono', $datos)) {
            $telefono = trim((string) $datos['telefono']);
            $datos['telefono'] = $telefono === '' ? null : $telefono;
        }

        return $datos;
    }

    /**
     * @param  int|null  $usuarioId  El usuario_id del registro de contacto;
     *                               null si todavía no tiene cuenta.
     * @param  array  $datos  Lo que se editó. Solo se sincronizan los campos
     *                        que vengan: editar solo el teléfono no toca el nombre.
     */
    public function ejecutar(?int $usuarioId, array $datos): void
    {
        if ($usuarioId === null) {
            return;
        }

        $cambios = array_intersect_key($this->normalizar($datos), array_flip(self::CAMPOS));

        if ($cambios === []) {
            return;
        }

        Usuario::whereKey($usuarioId)->update($cambios);
    }
}
