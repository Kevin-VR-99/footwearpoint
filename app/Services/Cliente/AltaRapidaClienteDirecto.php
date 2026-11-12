<?php

namespace App\Services\Cliente;

use App\Models\ClienteDirecto;
use App\Services\Distribuidora\GestionarClienteDirectoAction;
use App\Services\Perfil\FotoPerfil;
use App\Support\TelefonoCliente;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/**
 * Alta rápida de cliente directo (la misma de «¿Primera compra?» en el pedido).
 *
 * El teléfono se normaliza a 10 dígitos. Si ya existe en esta distribuidora,
 * no se crea otro: se devuelve el que ya está.
 */
class AltaRapidaClienteDirecto
{
    public function __construct(
        private GestionarClienteDirectoAction $clientes,
        private FotoPerfil $fotos,
    ) {
    }

    /**
     * @return array{cliente: ClienteDirecto, nuevo: bool}
     */
    public function resolver(
        string $nombre,
        string $telefonoEscrito,
        ?string $correo = null,
        ?string $direccion = null,
        ?UploadedFile $foto = null,
    ): array {
        $telefono = TelefonoCliente::normalizar($telefonoEscrito);

        if ($telefono === null) {
            throw ValidationException::withMessages([
                'telefono' => [TelefonoCliente::MENSAJE_DIGITOS],
            ]);
        }

        $existente = ClienteDirecto::query()->where('telefono', $telefono)->first();

        if ($existente) {
            return ['cliente' => $existente, 'nuevo' => false];
        }

        $cliente = $this->clientes->crear([
            'nombre' => trim($nombre),
            'telefono' => $telefono,
            'email' => $correo !== null && trim($correo) !== '' ? trim($correo) : null,
            'direccion_contacto' => $direccion !== null && trim($direccion) !== '' ? trim($direccion) : null,
        ]);

        if ($foto !== null) {
            $cliente->forceFill([
                'foto_url' => $this->fotos->guardar($foto, 'clientes-directos', $cliente->foto_url),
            ])->save();
        }

        return ['cliente' => $cliente->fresh(), 'nuevo' => true];
    }
}
