<?php

namespace App\Services\Catalogo;

use App\Rules\SvgSeguro;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Logotipos de líneas y marcas: misma validación, mismo disco y la misma
 * limpieza del archivo anterior al reemplazarlo o quitarlo.
 */
class LogotipoDeCatalogo
{
    public const DISCO = 's3';

    public const MAXIMO_KB = 2048;

    /** Reglas compartidas por los modales y el API. */
    public static function reglas(): array
    {
        return [
            'nullable',
            'file',
            'mimes:jpg,jpeg,png,webp,svg',
            'mimetypes:image/jpeg,image/png,image/webp,image/svg+xml,image/svg',
            'max:'.self::MAXIMO_KB,
            new SvgSeguro(),
        ];
    }

    public static function mensajes(string $campo): array
    {
        return [
            $campo.'.mimes' => 'El logo debe ser JPG, PNG, WebP o SVG.',
            $campo.'.mimetypes' => 'El logo debe ser JPG, PNG, WebP o SVG.',
            $campo.'.max' => 'El logo no puede pesar más de 2 MB.',
            $campo.'.file' => 'No se pudo leer el archivo del logo.',
        ];
    }

    /** Sube el logo a {carpeta}/logotipos y devuelve su URL pública. */
    public function subir(UploadedFile $logo, string $carpeta): string
    {
        $ruta = $logo->store($carpeta.'/logotipos', self::DISCO);

        return Storage::disk(self::DISCO)->url($ruta);
    }

    /**
     * Borra el archivo de un logo que ya no se usa. Solo toca archivos que
     * subimos nosotros a {carpeta}/logotipos; una URL externa se deja en paz.
     */
    public function borrar(?string $url, string $carpeta): void
    {
        $ruta = $this->rutaDe($url, $carpeta);

        if ($ruta === null) {
            return;
        }

        try {
            Storage::disk(self::DISCO)->delete($ruta);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    private function rutaDe(?string $url, string $carpeta): ?string
    {
        if (blank($url)) {
            return null;
        }

        $base = rtrim(Storage::disk(self::DISCO)->url(''), '/').'/';
        $ruta = str_starts_with($url, $base) ? substr($url, strlen($base)) : null;

        if ($ruta === null || ! str_starts_with($ruta, $carpeta.'/logotipos/') || str_contains($ruta, '..')) {
            return null;
        }

        return $ruta;
    }
}
