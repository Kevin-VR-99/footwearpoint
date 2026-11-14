<?php

namespace App\Services\Distribuidora;

use App\Exceptions\OperacionInvalidaException;
use App\Models\ClienteDirecto;
use App\Services\Auditoria\AuditarCambios;
use App\Support\TelefonoCliente;
use App\Support\Tenant;
use Illuminate\Validation\ValidationException;

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
        $cliente = ClienteDirecto::create([
            'nombre'             => $datos['nombre'],
            'telefono'           => $this->telefono($datos),
            'email'              => $datos['email'] ?? null,
            'direccion_contacto' => $datos['direccion_contacto'] ?? null,
            'notas'              => $datos['notas'] ?? null,
            'estado'             => 'activo',
        ]);

        // TG-334
        app(AuditarCambios::class)->creado($cliente, 'cliente_directo.creado', 'cliente_directo');

        return $cliente;
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
            $datos['telefono'] = $this->telefono($datos, (int) $cliente->id);
            if ($datos['telefono'] !== null) {
                $datos['telefono_por_recapturar'] = false;
            }
        }

        $cliente->fill($datos);
        // TG-334: guarda y deja en la auditoría solo lo que cambió.
        app(AuditarCambios::class)->guardar($cliente, 'cliente_directo.actualizado', 'cliente_directo');

        return $cliente->fresh();
    }

    /**
     * Vacío se guarda como null. Si escribieron algo, tiene que quedar en
     * 10 dígitos y no repetirse en la misma distribuidora.
     */
    private function telefono(array $datos, ?int $exceptoId = null): ?string
    {
        $escrito = trim((string) ($datos['telefono'] ?? ''));

        if ($escrito === '') {
            return null;
        }

        $telefono = TelefonoCliente::normalizar($escrito);

        if ($telefono === null) {
            throw ValidationException::withMessages([
                'telefono' => [TelefonoCliente::MENSAJE_DIGITOS],
            ]);
        }

        $distribuidoraId = (int) ($datos['distribuidora_id'] ?? Tenant::id());
        $ocupado = ClienteDirecto::query()
            ->when($exceptoId !== null, fn ($q) => $q->whereKeyNot($exceptoId))
            ->where('telefono', $telefono)
            ->when($distribuidoraId > 0, fn ($q) => $q->where('distribuidora_id', $distribuidoraId))
            ->exists();

        if ($ocupado) {
            throw ValidationException::withMessages([
                'telefono' => [TelefonoCliente::MENSAJE_REPETIDO],
            ]);
        }

        return $telefono;
    }
}
