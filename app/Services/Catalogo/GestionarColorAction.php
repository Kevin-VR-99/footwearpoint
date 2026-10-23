<?php

namespace App\Services\Catalogo;

use App\Exceptions\OperacionInvalidaException;
use App\Models\Color;
use App\Models\Variante;
use App\Services\Auditoria\AuditarCambios;
use Illuminate\Support\Facades\DB;

/**
 * TG-282 — La paleta de colores del catálogo maestro.
 *
 * La paleta no es un adorno: de ella sale la lista que se le manda a la IA al
 * leer un catálogo ("elige uno exactamente de esta lista"), y es la que
 * agrupa y filtra en la tienda. Con los ocho colores sembrados, un catálogo
 * de calzado de dama se queda corto: "MALVA" y "MAQUILLAJE" no tienen a
 * dónde ir, y la IA los deja sin resolver, que es lo correcto.
 *
 * Antes de esto no había forma de agregar uno sin meter mano a la base.
 */
class GestionarColorAction
{
    public function __construct(private AuditarCambios $auditar)
    {
    }

    public function crear(array $datos): Color
    {
        $color = Color::create([
            'nombre'     => trim($datos['nombre']),
            'codigo_hex' => $this->hex($datos['codigo_hex'] ?? null),
            'activo'     => true,
        ]);

        $this->auditar->creado($color, 'catalogo.color.creado', 'color');

        return $color;
    }

    public function actualizar(Color $color, array $datos): Color
    {
        if (array_key_exists('nombre', $datos)) {
            $datos['nombre'] = trim($datos['nombre']);
        }

        if (array_key_exists('codigo_hex', $datos)) {
            $datos['codigo_hex'] = $this->hex($datos['codigo_hex']);
        }

        $color->fill($datos);
        $this->auditar->guardar($color, 'catalogo.color.actualizado', 'color');

        return $color->fresh();
    }

    /**
     * Solo se borra un color que nadie usa.
     *
     * Una variante guarda su color_id, así que borrar uno en uso dejaría
     * productos sin color. Para sacarlo de circulación sin romper nada está
     * desactivarlo: deja de ofrecerse a la IA y en los formularios, pero lo
     * que ya se vendió con ese color se queda como está.
     */
    public function eliminar(Color $color): void
    {
        $enUso = Variante::withoutGlobalScopes()->where('color_id', $color->id)->count();

        if ($enUso > 0) {
            throw new OperacionInvalidaException(
                "No se puede eliminar el color: lo usan {$enUso} variantes. Desactívalo en su lugar."
            );
        }

        DB::transaction(function () use ($color) {
            $this->auditar->eliminado($color, 'catalogo.color.eliminado', 'color');
            $color->delete();
        });
    }

    /** Acepta "#1E3A8A" o "1e3a8a"; cualquier otra cosa se guarda en null. */
    private function hex(?string $valor): ?string
    {
        $valor = trim((string) $valor);

        if ($valor === '') {
            return null;
        }

        $valor = '#'.ltrim($valor, '#');

        return preg_match('/^#[0-9a-fA-F]{6}$/', $valor) === 1 ? strtoupper($valor) : null;
    }
}
