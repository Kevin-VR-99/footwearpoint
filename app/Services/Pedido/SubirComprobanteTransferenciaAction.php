<?php

namespace App\Services\Pedido;

use App\Models\ComprobanteTransferencia;
use App\Models\Pedido;
use App\Support\PropietarioActual;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * El cliente del pedido sube el comprobante solo como archivo.
 * Subirlo no registra el pago ni cambia el saldo.
 */
class SubirComprobanteTransferenciaAction
{
    public function ejecutar(Pedido $pedido, UploadedFile $archivo): ComprobanteTransferencia
    {
        abort_if(PropietarioActual::esDeLaCasa(), 403, 'El comprobante lo sube el cliente del pedido.');

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
        $ruta = $pedido->distribuidora_id.'/'.$pedido->id.'/'.$nombre;
        $disco = ComprobanteTransferencia::DISCO;

        $anterior = ComprobanteTransferencia::query()->where('pedido_id', $pedido->id)->first();

        Storage::disk($disco)->put($ruta, $archivo->get());

        if ($anterior !== null) {
            Storage::disk($anterior->disco)->delete($anterior->ruta);
            $anterior->update([
                'disco' => $disco,
                'ruta' => $ruta,
                'nombre_original' => $archivo->getClientOriginalName(),
                'mime' => $archivo->getMimeType() ?: 'application/octet-stream',
                'tamano' => $archivo->getSize(),
                'subido_por_usuario_id' => Auth::id(),
            ]);

            return $anterior->fresh();
        }

        return ComprobanteTransferencia::create([
            'distribuidora_id' => $pedido->distribuidora_id,
            'pedido_id' => $pedido->id,
            'disco' => $disco,
            'ruta' => $ruta,
            'nombre_original' => $archivo->getClientOriginalName(),
            'mime' => $archivo->getMimeType() ?: 'application/octet-stream',
            'tamano' => $archivo->getSize(),
            'subido_por_usuario_id' => Auth::id(),
        ]);
    }
}
