<?php

namespace App\Services\Ia;

/**
 * TG-238 (A8) — Cuántas páginas tiene el PDF.
 *
 * Hace falta para saber en cuántos bloques se parte el trabajo. Se cuenta
 * aquí, sin librerías, porque un PDF guarda ese dato de dos maneras distintas
 * y con revisar las dos alcanza. Probado con los dos catálogos reales:
 *
 *   - Confort (76 páginas, PDF 1.7): no trae el /Count a la vista, pero sí
 *     los 76 objetos de página.
 *   - Impuls (122 páginas, PDF 1.6): al revés, los objetos de página están
 *     dentro de flujos comprimidos y no se ven, pero el /Count sí.
 *
 * Tomando el mayor de los dos, los dos salen bien. Si ninguno funciona se
 * devuelve 0 y quien llama avisa con un mensaje claro, en vez de procesar
 * medio catálogo en silencio.
 */
class ContarPaginasPdf
{
    public function contar(string $contenido): int
    {
        return max($this->porElTotalDeclarado($contenido), $this->porLosObjetosDePagina($contenido));
    }

    /** El /Count del nodo raiz de páginas. */
    private function porElTotalDeclarado(string $contenido): int
    {
        $mayor = 0;

        if (preg_match_all('#/Type\s*/Pages\b.{0,400}?/Count\s+(\d+)#s', $contenido, $coincidencias)) {
            foreach ($coincidencias[1] as $numero) {
                $mayor = max($mayor, (int) $numero);
            }
        }

        return $mayor;
    }

    /** Un objeto "/Type /Page" por página. El [^s] evita contar "/Pages". */
    private function porLosObjetosDePagina(string $contenido): int
    {
        return (int) preg_match_all('#/Type\s*/Page[^s]#', $contenido);
    }
}
