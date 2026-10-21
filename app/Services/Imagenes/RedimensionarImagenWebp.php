<?php

namespace App\Services\Imagenes;

use GdImage;
use RuntimeException;

/**
 * Auditoría 21-oct-2026 (punto 3) — Genera versiones WebP más chicas de una
 * foto con GD (sin librerías extra). Nunca agranda: si la foto ya es más
 * angosta que el ancho pedido, solo se convierte a WebP.
 */
class RedimensionarImagenWebp
{
    public const MINIATURA = 400;

    public const MEDIANA = 1200;

    public const CALIDAD = 80;

    /**
     * @param  array<int>  $anchos
     * @return array<int, array{contenido: string, ancho: int, alto: int}> ancho pedido => WebP
     */
    public function generar(string $rutaArchivo, array $anchos = [self::MINIATURA, self::MEDIANA]): array
    {
        $original = $this->abrir($rutaArchivo);

        try {
            $resultado = [];
            foreach ($anchos as $ancho) {
                $resultado[$ancho] = $this->aWebp($original, $ancho);
            }

            return $resultado;
        } finally {
            imagedestroy($original);
        }
    }

    private function abrir(string $ruta): GdImage
    {
        if (! function_exists('imagewebp')) {
            throw new RuntimeException('GD no tiene soporte para WebP.');
        }

        $contenido = @file_get_contents($ruta);
        $imagen = $contenido !== false ? @imagecreatefromstring($contenido) : false;

        if (! $imagen instanceof GdImage) {
            throw new RuntimeException('No se pudo leer la imagen.');
        }

        return $this->orientar($imagen, $ruta);
    }

    /** Las fotos de celular traen la rotación en EXIF; GD no la aplica sola. */
    private function orientar(GdImage $imagen, string $ruta): GdImage
    {
        if (! function_exists('exif_read_data')) {
            return $imagen;
        }

        $exif = @exif_read_data($ruta);
        $angulo = match ((int) ($exif['Orientation'] ?? 1)) {
            3 => 180,
            6 => -90,
            8 => 90,
            default => 0,
        };

        if ($angulo === 0) {
            return $imagen;
        }

        $rotada = imagerotate($imagen, $angulo, 0);
        if ($rotada instanceof GdImage) {
            imagedestroy($imagen);

            return $rotada;
        }

        return $imagen;
    }

    /** @return array{contenido: string, ancho: int, alto: int} */
    private function aWebp(GdImage $original, int $anchoMaximo): array
    {
        $ancho = imagesx($original);
        $alto = imagesy($original);

        $nuevoAncho = min($ancho, $anchoMaximo);
        $nuevoAlto = max(1, (int) round($alto * $nuevoAncho / $ancho));

        $lienzo = imagecreatetruecolor($nuevoAncho, $nuevoAlto);
        imagealphablending($lienzo, false);
        imagesavealpha($lienzo, true);
        imagecopyresampled($lienzo, $original, 0, 0, 0, 0, $nuevoAncho, $nuevoAlto, $ancho, $alto);

        ob_start();
        imagewebp($lienzo, null, self::CALIDAD);
        $contenido = (string) ob_get_clean();
        imagedestroy($lienzo);

        return ['contenido' => $contenido, 'ancho' => $nuevoAncho, 'alto' => $nuevoAlto];
    }
}
