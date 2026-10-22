<?php

namespace App\Services\Distribuidora;

use App\Models\Distribuidora;
use App\Support\WhatsApp;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class ActualizarPerfilDistribuidoraAction
{
    public function ejecutar(Distribuidora $distribuidora, array $datos, ?UploadedFile $logo = null): Distribuidora
    {
        $datos = $this->resolverWhatsApp($distribuidora, $datos);

        if ($logo) {
            // Disco "compatible con S3" configurado en Fase 0 (config/filesystems.php,
            // sección 4 del documento de tareas). El proveedor exacto queda pendiente
            // por presupuesto, pero el disco ya debe apuntar a alguno.
            $ruta = $logo->store('distribuidoras/logotipos', 's3');
            $datos['logotipo_url'] = Storage::disk('s3')->url($ruta);
        }

        $distribuidora->fill($datos);
        $distribuidora->save();

        return $distribuidora->fresh();
    }

    /**
     * Sin teléfono, o con un número que WhatsApp no puede abrir, el contacto
     * queda apagado. Así el botón de la tienda no aparece por tener el campo lleno.
     *
     * @param  array<string, mixed>  $datos
     * @return array<string, mixed>
     */
    private function resolverWhatsApp(Distribuidora $distribuidora, array $datos): array
    {
        $tocaTelefono = array_key_exists('telefono_publico', $datos);
        $tocaWhatsApp = array_key_exists('telefono_es_whatsapp', $datos);

        if (! $tocaTelefono && ! $tocaWhatsApp) {
            return $datos;
        }

        $telefono = $tocaTelefono ? $datos['telefono_publico'] : $distribuidora->telefono_publico;
        $quiere = $tocaWhatsApp
            ? filter_var($datos['telefono_es_whatsapp'], FILTER_VALIDATE_BOOLEAN)
            : (bool) $distribuidora->telefono_es_whatsapp;

        $datos['telefono_es_whatsapp'] = $quiere && WhatsApp::normalizarTelefono(is_string($telefono) ? $telefono : null) !== null;

        return $datos;
    }
}
