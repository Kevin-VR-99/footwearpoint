<?php

namespace App\Services\Perfil;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Fotos de perfil de empleados y clientes mayoristas. Van al mismo disco de
 * imágenes que el resto del proyecto (s3 / R2) bajo perfiles/… y se guarda la
 * URL pública, igual que el logotipo de la distribuidora.
 */
class FotoPerfil
{
    public const DISCO = 's3';

    public const CARPETA = 'perfiles/';

    /** Reglas de validación compartidas por el panel y la API. */
    public static function reglas(): array
    {
        return ['image', 'mimes:jpg,jpeg,png,webp', 'max:2048'];
    }

    public static function mensajes(string $campo): array
    {
        return [
            "{$campo}.image" => 'La foto debe ser una imagen.',
            "{$campo}.mimes" => 'La foto debe ser JPG, PNG o WebP.',
            "{$campo}.max"   => 'La foto no puede pesar más de 2 MB.',
            "{$campo}.uploaded" => 'No se pudo subir la foto. Intenta con una más ligera.',
        ];
    }

    /** Guarda la foto nueva, borra la anterior (si era nuestra) y regresa la URL. */
    public function guardar(UploadedFile $archivo, string $subcarpeta, ?string $urlAnterior = null): string
    {
        $ruta = $archivo->store(self::CARPETA.$subcarpeta, self::DISCO);
        $url = Storage::disk(self::DISCO)->url($ruta);

        $this->borrar($urlAnterior);

        return $url;
    }

    /** Solo borra archivos que viven en perfiles/…; cualquier otra URL se deja. */
    public function borrar(?string $url): void
    {
        $posicion = $url === null ? false : strpos($url, self::CARPETA);

        if ($posicion === false) {
            return;
        }

        try {
            Storage::disk(self::DISCO)->delete(substr($url, $posicion));
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
