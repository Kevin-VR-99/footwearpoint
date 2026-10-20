<?php

namespace App\Services\Ia;

use App\Models\Color;
use App\Models\DisponibilidadVarianteCampana;
use App\Models\ImportacionCatalogo;
use App\Models\Marca;
use App\Models\Producto;
use App\Models\ProductoCampana;
use App\Models\ProductoImportadoStaging;
use App\Models\Talla;
use App\Models\Variante;
use App\Services\Auditoria\RegistrarAuditoriaAction;
use App\Services\Catalogo\GestionarProductoAction;
use App\Services\Catalogo\GestionarProductoCampanaAction;
use App\Services\Catalogo\GestionarVarianteAction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * TG-240 (A10 / E5-04) — Los productos revisados pasan al catálogo maestro.
 *
 * Es el último paso de la importación. Usa LAS MISMAS acciones que el alta a
 * mano (GestionarProducto, GestionarVariante, GestionarProductoCampana),
 * que es justo lo que pide el criterio: "al aprobar, el producto sigue el
 * mismo flujo que un producto creado manualmente".
 *
 * Solo se aprueba lo que una persona ya revisó (estado "corregido"). Lo que
 * sigue en "pendiente" se queda y se reporta: si no, la revisión de A9 no
 * serviría de nada.
 *
 * Lo que crea por cada producto, en cadena:
 *
 *     producto -> variantes (una por talla) -> producto en la temporada
 *              -> disponibilidad de cada variante
 *
 * Sin la cadena completa el producto quedaría sin tallas y nadie podría
 * pedirlo.
 *
 * EL PRODUCTO QUE YA EXISTE (lo señaló el líder, y pasa en cuanto se suban
 * Impuls y Cklass, porque el catálogo demo ya está sembrado):
 *
 *  - La base tiene únicos en productos (marca, modelo), en la temporada
 *    (temporada, producto) y en (temporada, código).
 *  - Si el producto ya existe se REUTILIZA y se le agregan las tallas que le
 *    falten; no se duplica ni se le pisan sus datos.
 *  - Si además ya estaba en esa misma temporada, se SALTA y se reporta.
 *    Nunca se le cambia el precio en silencio: ese precio lo puso alguien.
 */
class AprobarProductosImportadosAction
{
    public function __construct(
        private GestionarProductoAction $productos,
        private GestionarVarianteAction $variantes,
        private GestionarProductoCampanaAction $productosCampana,
        private RangoDeTallas $rangoDeTallas,
        private RegistrarAuditoriaAction $auditoria,
        private ColoresDelCatalogo $colores,
    ) {
    }

    /**
     * @param  int  $campanaId        La temporada a la que entran.
     * @param  int  $categoriaPorOmision  Para los que no traigan la suya.
     * @param  int  $marcaPorOmision      Obligatoria: la marca viene vacía muchas veces.
     * @return array<string, mixed>  Lo que pasó, para enseñarlo en pantalla.
     */
    public function ejecutar(
        ImportacionCatalogo $importacion,
        int $campanaId,
        int $categoriaPorOmision,
        int $marcaPorOmision,
    ): array {
        $resumen = [
            'aprobados'          => 0,
            'productos_nuevos'   => 0,
            'productos_reusados' => 0,
            'variantes'          => 0,
            'saltados'           => [],
            'tallas_creadas'     => [],
            'pendientes'         => 0,
        ];

        $filas = $importacion->productosStaging()->where('estado', 'corregido')->get();

        $resumen['pendientes'] = $importacion->productosStaging()
            ->where('estado', 'pendiente')
            ->count();

        foreach ($filas as $fila) {
            try {
                DB::transaction(function () use ($fila, $campanaId, $categoriaPorOmision, $marcaPorOmision, &$resumen) {
                    $this->aprobarUno($fila, $campanaId, $categoriaPorOmision, $marcaPorOmision, $resumen);
                });
            } catch (Throwable $e) {
                // Uno que falla no detiene a los demás, y se dice cuál y por qué.
                $resumen['saltados'][] = $this->identificar($fila).': '.$e->getMessage();
            }
        }

        if ($resumen['aprobados'] > 0) {
            $importacion->update(['estado' => 'aprobado']);
        }

        $this->auditoria->ejecutar(
            accion: 'importacion_catalogo.aprobada',
            entidadTipo: 'importacion_catalogo',
            entidadId: $importacion->id,
            datosNuevos: [
                'campana_id' => $campanaId,
                'aprobados'  => $resumen['aprobados'],
                'reusados'   => $resumen['productos_reusados'],
                'saltados'   => count($resumen['saltados']),
            ],
        );

        return $resumen;
    }

    /** @param array<string, mixed> $resumen */
    private function aprobarUno(
        ProductoImportadoStaging $fila,
        int $campanaId,
        int $categoriaPorOmision,
        int $marcaPorOmision,
        array &$resumen,
    ): void {
        // Aprobar dos veces no duplica nada.
        if ($fila->producto_campana_creado_id !== null) {
            return;
        }

        $datos = $fila->datos_extraidos ?? [];

        $marcaId = $this->marcaDe($datos, $marcaPorOmision);
        $categoriaId = (int) ($datos['categoria_id'] ?? $categoriaPorOmision);
        $modelo = $this->modeloDe($datos);
        $codigo = $this->codigoDe($datos, $modelo);

        $producto = Producto::where('marca_id', $marcaId)->where('modelo', $modelo)->first();

        if ($producto === null) {
            $producto = $this->productos->crear([
                'marca_id'     => $marcaId,
                'categoria_id' => $categoriaId,
                'modelo'       => $modelo,
                'nombre'       => $this->nombreDe($datos, $categoriaId, $codigo),
            ]);

            $resumen['productos_nuevos']++;
        } else {
            // Ya existía: se reutiliza tal cual, sin pisarle sus datos.
            $resumen['productos_reusados']++;
        }

        $nuevasVariantes = $this->asegurarVariantes($producto, $datos, $resumen);

        if ($nuevasVariantes === [] && $producto->variantes()->count() === 0) {
            throw new InvalidArgumentException('no se entendió el rango de tallas, así que quedaría sin tallas');
        }

        // ¿Ya estaba en esta temporada? Entonces no se toca: su precio lo
        // puso alguien y cambiarlo en silencio seria peor que no hacer nada.
        $yaEnLaTemporada = ProductoCampana::where('campana_id', $campanaId)
            ->where('producto_id', $producto->id)
            ->first();

        if ($yaEnLaTemporada !== null) {
            $fila->update([
                'producto_creado_id' => $producto->id,
                'estado'             => 'aprobado',
            ]);

            $resumen['saltados'][] = $this->identificar($fila).': el producto ya estaba en esta temporada, no se cambió su precio';

            return;
        }

        $codigoOcupado = ProductoCampana::where('campana_id', $campanaId)
            ->where('codigo_catalogo', $codigo)
            ->exists();

        if ($codigoOcupado) {
            throw new InvalidArgumentException("el código {$codigo} ya lo usa otro producto en esta temporada");
        }

        $productoCampana = $this->productosCampana->crear([
            'producto_id'     => $producto->id,
            'campana_id'      => $campanaId,
            'codigo_catalogo' => $codigo,
            'precio_publico'  => (float) ($datos['precio_publico'] ?? 0),
        ]);

        $this->asegurarDisponibilidad($productoCampana, $producto, $datos);

        $fila->update([
            'producto_creado_id'         => $producto->id,
            'producto_campana_creado_id' => $productoCampana->id,
            'estado'                     => 'aprobado',
        ]);

        $resumen['aprobados']++;
    }

    /**
     * La marca del catálogo, si coincide con una que ya existe; si no, la del
     * lote. Nunca se crean marcas solas: productos tiene único (marca, modelo)
     * y una marca inventada partiría el catálogo en dos.
     */
    private function marcaDe(array $datos, int $porOmision): int
    {
        $nombre = trim((string) ($datos['marca'] ?? ''));

        if ($nombre === '') {
            return $porOmision;
        }

        $marca = Marca::whereRaw('LOWER(nombre) = ?', [Str::lower($nombre)])->first();

        return $marca?->id ?? $porOmision;
    }

    /** Confort identifica todo por el código; ahí el código hace de modelo. */
    private function modeloDe(array $datos): string
    {
        $modelo = trim((string) ($datos['modelo'] ?? ''));

        if ($modelo !== '') {
            return $modelo;
        }

        $codigo = trim((string) ($datos['codigo_catalogo'] ?? ''));

        if ($codigo === '') {
            throw new InvalidArgumentException('no tiene modelo ni código de catálogo');
        }

        return $codigo;
    }

    private function codigoDe(array $datos, string $modelo): string
    {
        $codigo = trim((string) ($datos['codigo_catalogo'] ?? ''));

        return $codigo !== '' ? $codigo : $modelo;
    }

    /**
     * El nombre lo ve el cliente en la app, así que el código solo no basta:
     * se arma con la categoría, como "Balerina 033-39". Se puede editar en la
     * pantalla de revisión antes de aprobar.
     */
    private function nombreDe(array $datos, int $categoriaId, string $codigo): string
    {
        $nombre = trim((string) ($datos['nombre'] ?? ''));

        if ($nombre !== '') {
            return $nombre;
        }

        $categoria = \App\Models\CategoriaProducto::find($categoriaId);

        return trim(($categoria?->nombre ?? 'Calzado').' '.$codigo);
    }

    /**
     * Crea las variantes que falten: una por talla del rango, con su color.
     *
     * Las tallas que no existan se CREAN (un catálogo de niño trae menores a
     * 22 y la tabla empieza ahí); se reportan para que quede claro qué se
     * agregó al catálogo.
     *
     * @param  array<string, mixed>  $resumen
     * @return array<int, Variante>
     */
    private function asegurarVariantes(Producto $producto, array $datos, array &$resumen): array
    {
        $valores = $this->rangoDeTallas->valores(
            $datos['talla_desde'] ?? null,
            $datos['talla_hasta'] ?? null,
            (bool) ($datos['medios_numeros'] ?? false),
        );

        if ($valores === []) {
            return [];
        }

        $color = $this->colorDe($datos);
        $creadas = [];

        foreach ($valores as $valor) {
            $talla = Talla::where('sistema', 'MX')->where('valor', $valor)->first();

            if ($talla === null) {
                $talla = Talla::create([
                    'sistema' => 'MX',
                    'valor'   => $valor,
                    'orden'   => (float) $valor,
                    'activa'  => true,
                ]);

                $resumen['tallas_creadas'][$valor] = true;
            }

            $existente = Variante::where('producto_id', $producto->id)
                ->where('talla_id', $talla->id)
                ->where('color_id', $color->id)
                ->first();

            if ($existente !== null) {
                continue;
            }

            $creadas[] = $this->variantes->crear([
                'producto_id'            => $producto->id,
                'talla_id'               => $talla->id,
                'color_id'               => $color->id,
                // El color ES el del catálogo ("MIEL" -> "Miel").
                'nombre_color_comercial' => $color->nombre,
            ]);

            $resumen['variantes']++;
        }

        return $creadas;
    }

    /**
     * El color tal cual lo leyó la IA ("MIEL" -> "Miel"). Si no existe en
     * `colores` se crea; ya no se pide emparejarlo con otro color.
     */
    private function colorDe(array $datos): Color
    {
        $color = $this->colores->resolver($datos['color'] ?? null);

        if ($color === null) {
            throw new InvalidArgumentException('no trae color: escríbelo en la revisión');
        }

        return $color;
    }

    /**
     * Qué tan disponible está cada variante. "Hasta agotar existencias" es
     * disponible: se puede pedir hoy. Lo que diga "bajo pedido" se marca
     * como tal, y lo que diga "agotado" no se puede pedir.
     */
    private function asegurarDisponibilidad(ProductoCampana $productoCampana, Producto $producto, array $datos): void
    {
        $texto = Str::lower(trim((string) ($datos['disponibilidad'] ?? '')));

        $estado = match (true) {
            str_contains($texto, 'bajo pedido')                               => 'bajo_pedido',
            str_contains($texto, 'agotado'), str_contains($texto, 'sin exis') => 'no_disponible',
            default                                                           => 'disponible',
        };

        foreach ($producto->variantes()->pluck('id') as $varianteId) {
            DisponibilidadVarianteCampana::firstOrCreate(
                ['producto_campana_id' => $productoCampana->id, 'variante_id' => $varianteId],
                ['estado' => $estado, 'fecha_verificacion' => now()],
            );
        }
    }

    private function identificar(ProductoImportadoStaging $fila): string
    {
        $datos = $fila->datos_extraidos ?? [];

        return 'Pág. '.($datos['pagina'] ?? '?').' · '.($datos['codigo_catalogo'] ?? $datos['modelo'] ?? 'sin código');
    }
}
