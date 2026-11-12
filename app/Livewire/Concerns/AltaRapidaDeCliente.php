<?php

namespace App\Livewire\Concerns;

use App\Services\Cliente\AltaRapidaClienteDirecto;
use App\Support\MensajeError;
use App\Support\TelefonoCliente;
use Illuminate\Validation\ValidationException;

/**
 * Alta rápida de cliente directo, la misma de «¿Primera compra?» en el pedido.
 * La pantalla asigna el id con asignarClienteDirecto().
 */
trait AltaRapidaDeCliente
{
    public string $busquedaCliente = '';

    public string $primeraCompra = 'no';

    public string $altaNombre = '';

    public string $altaTelefono = '';

    public string $altaCorreo = '';

    public string $altaDireccion = '';

    public $altaFoto = null;

    public string $avisoCliente = '';

    abstract protected function asignarClienteDirecto(int $id): void;

    public function crearAltaRapida(AltaRapidaClienteDirecto $alta): void
    {
        $this->avisoCliente = '';
        $this->errorMsg = '';

        $this->validate([
            'altaNombre' => 'required|string|max:150',
            'altaTelefono' => 'required|string|max:30',
            'altaCorreo' => 'nullable|email|max:190',
            'altaDireccion' => 'nullable|string|max:300',
            'altaFoto' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048',
        ], [
            'altaNombre.required' => 'Escribe el nombre.',
            'altaTelefono.required' => 'Escribe el teléfono.',
        ]);

        if (TelefonoCliente::normalizar($this->altaTelefono) === null) {
            $this->errorMsg = TelefonoCliente::MENSAJE_DIGITOS;

            return;
        }

        try {
            $resultado = $alta->resolver(
                $this->altaNombre,
                $this->altaTelefono,
                $this->altaCorreo,
                $this->altaDireccion,
                $this->altaFoto,
            );
        } catch (ValidationException $e) {
            $this->errorMsg = collect($e->errors())->flatten()->first() ?? 'No se pudo guardar el cliente.';

            return;
        } catch (\Throwable $e) {
            $this->errorMsg = MensajeError::paraUsuario($e, 'No se pudo guardar el cliente.');

            return;
        }

        $this->asignarClienteDirecto((int) $resultado['cliente']->id);
        $this->primeraCompra = 'no';
        $this->altaFoto = null;

        if ($resultado['nuevo']) {
            $this->avisoCliente = 'Cliente guardado: '.$resultado['cliente']->nombre.'.';
        } else {
            $this->avisoCliente = 'Ese teléfono ya está registrado. Se usará a '.$resultado['cliente']->nombre.'.';
        }
    }
}
