<?php

namespace App\Console\Commands;

use App\Services\Respaldo\RespaldarBaseDeDatosAction;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * php artisan pruebas:limpiar-produccion --conservar-importacion=5 [--confirmar]
 *
 * Deja producción lista para probar con el catálogo real:
 *
 * - Borra TODOS los pedidos y lo que cuelga de ellos (detalle, historial,
 *   pagos de pedido, reembolsos, vales y sus movimientos, solicitudes de
 *   cambio, ventas directas, cajas), las notificaciones de pedidos/pagos/vales
 *   (se quedan las de pago_suscripcion) y los webhooks de Mercado Pago que no
 *   sean de un pago de suscripción.
 * - Borra TODO el stock local y sus movimientos, y las auditorías de la
 *   distribuidora 1 (Calzados Ramírez).
 * - Borra POR COMPLETO (sin desactivar) todo producto de temporada y modelo
 *   que no haya creado una de las importaciones indicadas en
 *   --conservar-importacion. El orden es a propósito: primero los pedidos,
 *   así ningún RESTRICT impide borrar el catálogo y no queda historial.
 * - Las fotos borradas se quitan de R2 DESPUÉS del commit, y solo si ninguna
 *   foto que se queda usa la misma ruta.
 *
 * Se quedan: usuarios, staff, distribuidoras, suscripciones y sus pagos,
 * planes, líneas, marcas, campañas, ciclos, clientes directos, revendedores,
 * staging de importaciones y el catálogo de las importaciones conservadas.
 *
 * Sin --confirmar solo enseña lo que haría. Con --confirmar todo va en una
 * sola transacción y exige un respaldo de menos de 24 h (salvo
 * --sin-verificar-respaldo) y que no haya vales con saldo (salvo
 * --incluir-vales-con-saldo).
 */
class LimpiarProduccionPruebas extends Command
{
    protected $signature = 'pruebas:limpiar-produccion
        {--conservar-importacion= : IDs de importaciones_catalogo cuyo catálogo se queda, separados por coma}
        {--confirmar : Borra de verdad; sin esto solo enseña lo que haría}
        {--sin-verificar-respaldo : No exigir un respaldo de menos de 24 h}
        {--incluir-vales-con-saldo : Borrar también vales que aún tienen saldo}';

    protected $description = 'Borra pedidos, stock y catálogo demo de producción; deja solo el catálogo importado indicado';

    private const CARPETA_FOTOS = 'productos/campana/imagenes/';

    public function handle(): int
    {
        $confirmar = (bool) $this->option('confirmar');

        $this->mostrarImportaciones();

        $conservar = collect(explode(',', (string) $this->option('conservar-importacion')))
            ->map(fn ($id) => trim($id))->filter(fn ($id) => ctype_digit($id))->map(fn ($id) => (int) $id)->values();

        if ($conservar->isEmpty()) {
            $this->error('Indica qué importaciones se quedan: --conservar-importacion=ID[,ID]');

            return self::FAILURE;
        }

        $faltan = $conservar->diff(DB::table('importaciones_catalogo')->whereIn('id', $conservar)->pluck('id'));
        if ($faltan->isNotEmpty()) {
            $this->error('No existen las importaciones: '.$faltan->implode(', '));

            return self::FAILURE;
        }

        $this->info('Se conserva el catálogo de las importaciones: '.$conservar->implode(', '));

        $vales = $this->valesConSaldo();
        if ($vales->isNotEmpty()) {
            $this->warn('Vales con saldo que se borrarían:');
            $this->table(['ID', 'Folio', 'Saldo', 'Distribuidora', 'Dueño', 'Pedido origen'], $vales->map(fn ($v) => [
                $v->id, $v->folio, $v->saldo_actual, $v->distribuidora_id,
                trim(($v->cliente ?? $v->revendedor ?? '').' '.($v->email ?? '')),
                $v->pedido_origen_id ? "#{$v->pedido_origen_id} ({$v->pedido_creado})" : '—',
            ])->all());
        }

        $plan = $this->plan($conservar);

        $this->table(['Tabla', 'Filas'], collect($plan)->map(fn ($q, $tabla) => [$tabla, $q->count()])->values()->all());

        $fotos = $this->fotosABorrar($conservar);
        $this->line('Fotos de R2 que se borrarían: '.count($fotos));

        if (! $confirmar) {
            $this->info('Simulación: no se borró nada. Usa --confirmar para aplicar.');

            return self::SUCCESS;
        }

        if ($vales->isNotEmpty() && ! $this->option('incluir-vales-con-saldo')) {
            $this->error('Hay vales con saldo. Revisa la lista y usa --incluir-vales-con-saldo si de verdad se borran.');

            return self::FAILURE;
        }

        if (! $this->option('sin-verificar-respaldo') && ! $this->hayRespaldoReciente()) {
            $this->error('No hay un respaldo de menos de 24 h en el disco de respaldos. Corre respaldo:ejecutar o usa --sin-verificar-respaldo.');

            return self::FAILURE;
        }

        DB::transaction(function () use ($plan) {
            foreach ($plan as $q) {
                $q->delete();
            }
        });

        $borradas = 0;
        foreach ($fotos as $ruta) {
            try {
                if (Storage::disk('s3')->delete($ruta)) {
                    $borradas++;
                }
            } catch (Throwable $e) {
                $this->warn("No se pudo borrar {$ruta} de R2: {$e->getMessage()}");
            }
        }

        $this->info("Hecho. Fotos quitadas de R2: {$borradas} de ".count($fotos).'.');

        return self::SUCCESS;
    }

    /**
     * Consultas de borrado, en orden de llaves foráneas (hijos antes que padres).
     *
     * @return array<string, Builder>
     */
    private function plan(Collection $conservar): array
    {
        $pcConservados = DB::table('productos_importados_staging')->whereIn('importacion_id', $conservar)
            ->whereNotNull('producto_campana_creado_id')->pluck('producto_campana_creado_id');
        $productosConservados = DB::table('productos_importados_staging')->whereIn('importacion_id', $conservar)
            ->whereNotNull('producto_creado_id')->pluck('producto_creado_id')
            ->merge(DB::table('producto_campana')->whereIn('id', $pcConservados)->pluck('producto_id'))->unique()->values();

        $pcBorrar = DB::table('producto_campana')->whereNotIn('id', $pcConservados)->pluck('id');
        $productosBorrar = DB::table('productos')->whereNotIn('id', $productosConservados)->pluck('id');
        $variantesBorrar = DB::table('variantes')->whereIn('producto_id', $productosBorrar)->pluck('id');

        $pagosSuscripcion = DB::table('pagos')->whereNotNull('suscripcion_id');
        $refsSuscripcion = (clone $pagosSuscripcion)->whereNotNull('referencia_externa')->pluck('referencia_externa');
        $cajasConSuscripcion = (clone $pagosSuscripcion)->whereNotNull('caja_sesion_id')->pluck('caja_sesion_id');

        return [
            'solicitudes_cambio_historial' => DB::table('solicitudes_cambio_historial'),
            'solicitudes_cambio' => DB::table('solicitudes_cambio'),
            'pedido_cliente_privado' => DB::table('pedido_cliente_privado'),
            'vale_movimientos' => DB::table('vale_movimientos'),
            'vales' => DB::table('vales'),
            'reembolsos_mercado_pago' => DB::table('reembolsos_mercado_pago')
                ->whereIn('pago_id', DB::table('pagos')->whereNull('suscripcion_id')->select('id')),
            'pagos (de pedido/venta)' => DB::table('pagos')->whereNull('suscripcion_id'),
            'movimientos_stock' => DB::table('movimientos_stock'),
            'venta_directa_detalle' => DB::table('venta_directa_detalle'),
            'ventas_directas' => DB::table('ventas_directas'),
            'cajas_movimientos' => DB::table('cajas_movimientos')->whereNotIn('caja_sesion_id', $cajasConSuscripcion),
            'cajas_sesiones' => DB::table('cajas_sesiones')->whereNotIn('id', $cajasConSuscripcion),
            'historial_estados_pedido' => DB::table('historial_estados_pedido'),
            'pedido_detalle' => DB::table('pedido_detalle'),
            'pedidos' => DB::table('pedidos'),
            'stock_local' => DB::table('stock_local'),
            'conteos_inventario_renglones' => DB::table('conteos_inventario_renglones')->whereIn('variante_id', $variantesBorrar),
            'notificaciones (no pago_suscripcion)' => DB::table('notificaciones')->where('tipo', '!=', 'pago_suscripcion'),
            'webhooks_mercado_pago (no suscripción)' => DB::table('webhooks_mercado_pago')->whereNotIn('recurso_id', $refsSuscripcion),
            'auditorias (distribuidora 1)' => DB::table('auditorias')->where('distribuidora_id', 1),
            'producto_imagenes' => DB::table('producto_imagenes')->whereIn('producto_campana_id', $pcBorrar),
            'disponibilidad_variante_campana' => DB::table('disponibilidad_variante_campana')
                ->where(fn ($q) => $q->whereIn('producto_campana_id', $pcBorrar)->orWhereIn('variante_id', $variantesBorrar)),
            'ofertas_distribuidora' => DB::table('ofertas_distribuidora')->whereIn('producto_campana_id', $pcBorrar),
            'productos_destacados' => DB::table('productos_destacados')->whereIn('producto_campana_id', $pcBorrar),
            'producto_campana' => DB::table('producto_campana')->whereIn('id', $pcBorrar),
            'variantes' => DB::table('variantes')->whereIn('id', $variantesBorrar),
            'productos' => DB::table('productos')->whereIn('id', $productosBorrar),
        ];
    }

    private function mostrarImportaciones(): void
    {
        $conservar = collect(explode(',', (string) $this->option('conservar-importacion')))->map(fn ($id) => (int) trim($id));

        $filas = DB::table('importaciones_catalogo as i')->leftJoin('lineas as l', 'l.id', '=', 'i.linea_id')
            ->orderBy('i.id')->get(['i.id', 'i.estado', 'i.created_at', 'l.nombre as linea'])
            ->map(fn ($i) => [
                $i->id, $i->linea, $i->estado, $i->created_at,
                DB::table('productos_importados_staging')->where('importacion_id', $i->id)->whereNotNull('producto_campana_creado_id')->count(),
                $conservar->contains($i->id) ? 'SE QUEDA' : 'se borra su catálogo',
            ]);

        $this->table(['Importación', 'Línea', 'Estado', 'Creada', 'Productos de temporada', 'Acción'], $filas->all());
    }

    private function valesConSaldo(): Collection
    {
        return DB::table('vales as v')
            ->leftJoin('clientes_directos as c', 'c.id', '=', 'v.cliente_directo_id')
            ->leftJoin('revendedor_distribuidora as rd', 'rd.id', '=', 'v.revendedor_distribuidora_id')
            ->leftJoin('revendedores as r', 'r.id', '=', 'rd.revendedor_id')
            ->leftJoin('usuarios as u', 'u.id', '=', DB::raw('coalesce(c.usuario_id, r.usuario_id)'))
            ->leftJoin('pedidos as p', 'p.id', '=', 'v.pedido_origen_id')
            ->where('v.saldo_actual', '>', 0)
            ->orderBy('v.id')
            ->get(['v.id', 'v.folio', 'v.saldo_actual', 'v.distribuidora_id', 'v.pedido_origen_id',
                'c.nombre as cliente', 'r.nombre as revendedor', 'u.email', 'p.created_at as pedido_creado']);
    }

    /** Rutas en R2 de las fotos que se borran y que ninguna foto conservada usa. */
    private function fotosABorrar(Collection $conservar): array
    {
        $pcConservados = DB::table('productos_importados_staging')->whereIn('importacion_id', $conservar)
            ->whereNotNull('producto_campana_creado_id')->pluck('producto_campana_creado_id');

        $rutas = fn ($imagenes) => $imagenes->flatMap(fn ($i) => [$i->url, $i->url_miniatura, $i->url_mediana])
            ->map(fn ($url) => $this->rutaEnR2($url))->filter()->unique();

        $columnas = ['url', 'url_miniatura', 'url_mediana'];
        $quedan = $rutas(DB::table('producto_imagenes')->whereIn('producto_campana_id', $pcConservados)->get($columnas));

        return $rutas(DB::table('producto_imagenes')->whereNotIn('producto_campana_id', $pcConservados)->get($columnas))
            ->diff($quedan)->values()->all();
    }

    private function rutaEnR2(?string $url): ?string
    {
        $pos = $url ? strpos($url, self::CARPETA_FOTOS) : false;

        return $pos === false ? null : strtok(substr($url, $pos), '?');
    }

    private function hayRespaldoReciente(): bool
    {
        $patron = '/'.preg_quote(RespaldarBaseDeDatosAction::PREFIJO, '/').'(\d{8}-\d{6})\.sql\.gz$/';
        $limite = now('UTC')->subDay();

        try {
            $archivos = Storage::disk(config('respaldos.disco'))->allFiles(trim((string) config('respaldos.carpeta'), '/'));
        } catch (Throwable $e) {
            $this->warn('No se pudo leer el disco de respaldos: '.$e->getMessage());

            return false;
        }

        foreach ($archivos as $archivo) {
            if (preg_match($patron, $archivo, $m) === 1 && Carbon::createFromFormat('Ymd-His', $m[1], 'UTC')->gte($limite)) {
                return true;
            }
        }

        return false;
    }
}
