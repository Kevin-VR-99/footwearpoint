<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

/**
 * Logos de líneas y marcas: un SVG puede traer código. Aquí no se intenta
 * "limpiarlo" (reescribir XML a mano deja huecos); si trae cualquier cosa
 * activa se rechaza completo y se pide otro archivo.
 *
 * Se rechaza: DOCTYPE o entidades (XXE), instrucciones de proceso
 * (xml-stylesheet), <script>, <foreignObject>, iframes/objetos, animaciones
 * (pueden cambiar un href a javascript:), atributos on*, href que no sea un
 * ancla interna (#id), y url()/@import/javascript: en estilos o atributos.
 * Los archivos que no son SVG no pasan por esta regla.
 */
class SvgSeguro implements ValidationRule
{
    private const ETIQUETAS_PROHIBIDAS = [
        'script', 'foreignobject', 'iframe', 'embed', 'object', 'audio', 'video',
        'handler', 'listener', 'set', 'animate', 'animatemotion', 'animatetransform',
        'animatecolor', 'discard', 'image', 'a',
    ];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile || ! self::esSvg($value)) {
            return;
        }

        if (! self::esSeguro((string) @file_get_contents($value->getRealPath()))) {
            $fail('El SVG trae código o referencias externas. Sube un SVG simple, o el logo en PNG, JPG o WebP.');
        }
    }

    public static function esSvg(UploadedFile $archivo): bool
    {
        return strtolower((string) $archivo->getClientOriginalExtension()) === 'svg'
            || str_contains((string) $archivo->getMimeType(), 'svg');
    }

    /**
     * La vista previa de Livewire sirve el archivo tal cual: solo se muestra
     * si es una imagen previsualizable y, si es SVG, si pasó esta revisión.
     */
    public static function previsualizable(mixed $archivo): bool
    {
        if (! $archivo instanceof UploadedFile || ! method_exists($archivo, 'isPreviewable') || ! $archivo->isPreviewable()) {
            return false;
        }

        return ! self::esSvg($archivo) || self::esSeguro((string) @file_get_contents($archivo->getRealPath()));
    }

    public static function esSeguro(string $contenido): bool
    {
        if (trim($contenido) === '' || preg_match('/<!DOCTYPE|<!ENTITY|<\?xml-stylesheet/i', $contenido)) {
            return false;
        }

        $dom = new \DOMDocument();
        $previo = libxml_use_internal_errors(true);
        $cargado = $dom->loadXML($contenido, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previo);

        if (! $cargado || ! $dom->documentElement || strtolower($dom->documentElement->localName) !== 'svg') {
            return false;
        }

        $xpath = new \DOMXPath($dom);

        foreach ($xpath->query('//processing-instruction()') as $_) {
            return false;
        }

        foreach ($xpath->query('//*') as $nodo) {
            if (in_array(strtolower($nodo->localName), self::ETIQUETAS_PROHIBIDAS, true)) {
                return false;
            }

            if (strtolower($nodo->localName) === 'style' && self::textoPeligroso($nodo->textContent)) {
                return false;
            }

            foreach ($nodo->attributes as $atributo) {
                $nombre = strtolower($atributo->localName);
                $valor = trim($atributo->value);

                if (str_starts_with($nombre, 'on')) {
                    return false;
                }

                if ($nombre === 'href' && ! str_starts_with($valor, '#')) {
                    return false;
                }

                if (self::textoPeligroso($valor)) {
                    return false;
                }
            }
        }

        return true;
    }

    /** url() solo se permite hacia un id del mismo SVG, p. ej. fill="url(#degradado)". */
    private static function textoPeligroso(string $texto): bool
    {
        $plano = preg_replace('/\s+/', '', strtolower($texto));

        return str_contains($plano, 'javascript:')
            || str_contains($plano, '@import')
            || str_contains($plano, 'expression(')
            || preg_match('/url\((?![\'"]?#)/', $plano) === 1;
    }
}
