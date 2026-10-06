<?php

namespace App\Services\Distribuidora;

use App\Exceptions\OperacionInvalidaException;
use App\Models\ClienteDirecto;

/**
 * Alta y edición de clientes directos desde el panel.
 *
 * Desde TG-216 este registro es el ÚNICO lugar donde viven el nombre y el
 * teléfono del cliente: la app edita exactamente este renglón desde su
 * pantalla de perfil, así que ya no hay nada que copiar a la cuenta.
 *
 * El correo es distinto: si el cliente ya tiene cuenta de la app, su correo es
 * el de acceso y vive en la cuenta; el del contacto solo se usa mientras no
 * tiene cuenta (D4).
 */
class GestionarClienteDirectoAction
{
    public function crear(array $datos): ClienteDirecto
    {
        // distribuidora_id se completa solo, vía BelongsToTenant (Fase 0).
        return ClienteDirecto::create([
            'nombre'             => $datos['nombre'],
            'telefono'           => $this->telefono($datos),
            'email'              => $datos['email'] ?? null,
            'direccion_contacto' => $datos['direccion_contacto'] ?? null,
            'notas'              => $datos['notas'] ?? null,
            'estado'             => 'activo',
        ]);
    }

    public function actualizar(ClienteDirecto $cliente, array $datos): ClienteDirecto
    {
        if ($cliente->usuario_id && array_key_exists('email', $datos)) {
            // Su correo es el de acceso a la app y se cambia desde su cuenta,
            // no desde aquí. Se avisa en vez de aceptarlo en silencio.
            throw new OperacionInvalidaException(
                'Este cliente ya tiene cuenta en la app: su correo es el de acceso y no se cambia desde aquí.',
                422
            );
        }

        if (array_key_exists('telefono', $datos)) {
            $datos['telefono'] = $this->telefono($datos);
        }

        $cliente->fill($datos);
        $cliente->save();

        return $cliente->fresh();
    }

    /** Un teléfono vacío se guarda como "sin teléfono", igual que en la app. */
    private function telefono(array $datos): ?string
    {
        $telefono = trim((string) ($datos['telefono'] ?? ''));

        return $telefono === '' ? null : $telefono;
    }
}
