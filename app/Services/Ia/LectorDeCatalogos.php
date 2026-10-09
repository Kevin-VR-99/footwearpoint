<?php

namespace App\Services\Ia;

/**
 * TG-238 (A8) — Lo que el sistema necesita de la IA, y nada más.
 *
 * Es una interfaz a propósito: así las pruebas usan un lector falso y nunca
 * gastan dinero ni necesitan la llave, y si algún día se cambia de proveedor
 * solo se escribe otra implementación.
 */
interface LectorDeCatalogos
{
    /**
     * Sube el catálogo una sola vez y devuelve el identificador con el que la
     * IA lo va a reconocer en las peticiones siguientes.
     */
    public function subir(string $contenido, string $nombre): string;

    /**
     * Pide los productos de un rango de páginas del catálogo ya subido.
     *
     * @param  int  $desde  Primera página, contando desde 1.
     */
    public function leerBloque(string $archivoIa, int $desde, int $hasta): BloqueDeCatalogo;

    /** Borra el catálogo del proveedor. Se llama termine bien o mal. */
    public function borrar(string $archivoIa): void;
}
