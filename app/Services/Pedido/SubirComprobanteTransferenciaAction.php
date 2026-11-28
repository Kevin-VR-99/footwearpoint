<?php

namespace App\Services\Pedido;

use App\Models\ComprobanteTransferencia;
use App\Models\ConfiguracionDistribuidora;
use App\Models\Pedido;
use App\Support\PropietarioActual;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * El cliente del pedido sube el comprobante solo como archivo.
 * Subirlo no registra el pago ni cambia el saldo.
 *
 * TG-411: el disco es COMPROBANTES_DISK (R2 privado en producción) y la ruta
 * lleva el prefijo comprobantes/. Al reemplazar, el archivo anterior se borra
 * de su propio disco, el que guardó su fila.
 */
class SubirComprobanteTransferenciaAction
{
    public function ejecutar(Pedido $pedido, UploadedFile $archivo): ComprobanteTransferencia
    {
        abort_if(PropietarioActual::esDeLaCasa(), 403, 'El comprobante lo sube el cliente del pedido.');

        if (! ConfiguracionDistribuidora::aceptaTransferenciasDe((int) $pedido->distribuidora_id)) {
            throw ValidationException::withMessages([
                'comprobante' => [DatosTransferenciaPedido::MENSAJE_NO_DISPONIBLE],
            ]);
        }

        $extension = strtolower($archivo->getClientOriginalExtension() ?: $archivo->extension() ?: '');

        if (! in_array($extension, ComprobanteTransferencia::MIMES, true)) {
            throw ValidationException::withMessages([
                'comprobante' => ['El comprobante tiene que ser PDF, JPG, PNG o WEBP.'],
            ]);
        }

        if ($archivo->getSize() > ComprobanteTransferencia::TAMANO_MAXIMO_KB * 1024) {
            throw ValidationException::withMessages([
                'comprobante' => ['El comprobante no puede pasar de 5 MB.'],
            ]);
        }

        $nombre = Str::uuid()->toString().'.'.$extension;
        $ruta = ComprobanteTransferencia::rutaPara($pedido, $nombre);
        $disco = ComprobanteTransferencia::disco();

        $anterior = ComprobanteTransferencia::query()->where('pedido_id', $pedido->id)->first();

        // El disco 'local' no lanza error si no puede escribir: sin archivo no
        // se guarda una fila que apunte a nada.
        if (Storage::disk($disco)->put($ruta, $archivo->get()) === false) {
            throw new \RuntimeException("No se pudo guardar el comprobante en el disco {$disco}.");
        }

        $datos = [
            'disco' => $disco,
            'ruta' => $ruta,
            'nombre_original' => $archivo->getClientOriginalName(),
            'mime' => $archivo->getMimeType() ?: 'application/octet-stream',
            'tamano' => $archivo->getSize(),
            'subido_por_usuario_id' => Auth::id(),
        ];

        try {
            if ($anterior === null) {
                return ComprobanteTransferencia::create([
                    'distribuidora_id' => $pedido->distribuidora_id,
                    'pedido_id' => $pedido->id,
                ] + $datos);
            }

            $archivoAnterior = [$anterior->disco, $anterior->ruta];
            $anterior->update($datos);
        } catch (Throwable $e) {
            // Sin fila que lo apunte, el archivo nuevo sobra.
            Storage::disk($disco)->delete($ruta);

            throw $e;
        }

        // TG-411: la fila ya apunta al nuevo. Si el anterior no se puede
        // borrar (p. ej. R2 caído), se reporta y el cliente no ve un error.
        try {
            Storage::disk($archivoAnterior[0])->delete($archivoAnterior[1]);
        } catch (Throwable $e) {
            report($e);
        }

        return $anterior->fresh();
    }
}
