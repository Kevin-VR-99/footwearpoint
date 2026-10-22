<?php

namespace App\Services\Inventario;

use App\Exceptions\OperacionInvalidaException;
use App\Models\ConteoInventario;
use App\Models\ConteoInventarioRenglon;
use App\Models\Producto;
use App\Models\StockLocal;
use App\Models\Variante;
use App\Services\Auditoria\RegistrarAuditoriaAction;
use App\Services\Auth\PermisoModuloService;
use App\Services\Stock\StockService;
use App\Support\ContextoOperativo;
use App\Support\MensajeError;
use Illuminate\Support\Facades\DB;

/**
 * Inventario físico (conteo). Se inicia por modelo o de todo lo que tiene
 * existencia en la sucursal; se captura con escáner (SKU) o a mano; las
 * diferencias se aplican como ajustes de stock en un solo paso, con
 * bitácora. Contar no toca el stock: solo aplicar.
 *
 * Producto y Variante son del catálogo compartido (TG-209); lo de cada
 * distribuidora es el conteo, sus renglones y su stock (con scope).
 */
class ConteoInventarioService
{
    public function __construct(
        private PermisoModuloService $permisos,
        private ContextoOperativo $contexto,
        private StockService $stock,
        private RegistrarAuditoriaAction $auditoria,
    ) {
    }

    public function iniciar(?int $productoId = null, ?string $notas = null): ConteoInventario
    {
        $this->exigir(PermisoModuloService::INVENTARIO_CONTEO);
        $staff = $this->contexto->staff() ?? throw new OperacionInvalidaException(MensajeError::SIN_PERMISO, 403);
        $sucursalId = (int) $this->contexto->sucursalPrincipal()->id;

        if ($productoId !== null && ! Producto::query()->whereKey($productoId)->exists()) {
            throw new OperacionInvalidaException('Ese modelo no existe en tu catálogo.', 404);
        }

        return DB::transaction(function () use ($productoId, $notas, $staff, $sucursalId) {
            $conteo = ConteoInventario::create([
                'sucursal_id' => $sucursalId, 'producto_id' => $productoId, 'estado' => 'abierto',
                'creado_por_staff_id' => $staff->id, 'notas' => $notas !== null ? mb_substr(trim($notas), 0, 500) : null,
            ]);

            $existencias = StockLocal::query()->where('sucursal_id', $sucursalId)->pluck('cantidad_disponible', 'variante_id');

            $variantes = $productoId !== null
                ? Variante::query()->where('producto_id', $productoId)->where('activa', true)->pluck('id')
                : $existencias->keys();

            foreach ($variantes as $varianteId) {
                ConteoInventarioRenglon::create([
                    'conteo_id' => $conteo->id, 'variante_id' => (int) $varianteId,
                    'esperado' => (int) ($existencias[$varianteId] ?? 0), 'contado' => null,
                ]);
            }

            $this->auditoria->ejecutar('inventario.conteo_iniciado', 'conteo_inventario', (int) $conteo->id, null,
                ['producto_id' => $productoId, 'renglones' => count($variantes)]);

            return $conteo;
        });
    }

    /** Lectura del escáner: suma piezas a la variante del SKU. */
    public function escanear(int $conteoId, string $sku, int $piezas = 1): ConteoInventarioRenglon
    {
        $this->exigir(PermisoModuloService::INVENTARIO_CONTEO);
        $sku = trim($sku);
        if ($sku === '') {
            throw new OperacionInvalidaException('Escanea o escribe un código.', 422);
        }
        if ($piezas < 1 || $piezas > 9999) {
            throw new OperacionInvalidaException('Las piezas deben ser de 1 a 9999.', 422);
        }

        return DB::transaction(function () use ($conteoId, $sku, $piezas) {
            $conteo = $this->abierto($conteoId);
            $variante = Variante::query()->where('sku', $sku)->first();

            if ($variante === null) {
                throw new OperacionInvalidaException("No encontramos el código {$sku} en tu catálogo.", 404);
            }
            if ($conteo->producto_id !== null && (int) $variante->producto_id !== (int) $conteo->producto_id) {
                throw new OperacionInvalidaException("El código {$sku} es de otro modelo; este conteo es solo de un modelo.", 422);
            }

            $renglon = ConteoInventarioRenglon::query()->where('conteo_id', $conteo->id)->where('variante_id', $variante->id)->lockForUpdate()->first()
                ?? ConteoInventarioRenglon::create([
                    'conteo_id' => $conteo->id, 'variante_id' => $variante->id, 'contado' => null,
                    'esperado' => (int) StockLocal::query()->where('sucursal_id', $conteo->sucursal_id)->where('variante_id', $variante->id)->value('cantidad_disponible'),
                ]);

            $renglon->contado = (int) ($renglon->contado ?? 0) + $piezas;
            $renglon->save();

            return $renglon;
        });
    }

    /** Captura a mano (o corrección). Nulo = «no lo conté». */
    public function fijarContado(int $conteoId, int $renglonId, ?int $contado): ConteoInventarioRenglon
    {
        $this->exigir(PermisoModuloService::INVENTARIO_CONTEO);
        if ($contado !== null && ($contado < 0 || $contado > 999999)) {
            throw new OperacionInvalidaException('Lo contado no puede ser negativo.', 422);
        }

        $conteo = $this->abierto($conteoId);
        $renglon = ConteoInventarioRenglon::query()->where('conteo_id', $conteo->id)->findOrFail($renglonId);
        $renglon->contado = $contado;
        $renglon->save();

        return $renglon;
    }

    /** @return array{contados:int,sin_contar:int,con_diferencia:int,sobrantes:int,faltantes:int} */
    public function resumen(ConteoInventario $conteo): array
    {
        $renglones = $conteo->renglones()->get();
        $contados = $renglones->whereNotNull('contado');
        $diferencias = $contados->map(fn ($r) => $r->diferencia());

        return [
            'renglones'      => $renglones->count(),
            'contados'       => $contados->count(),
            'sin_contar'     => $renglones->count() - $contados->count(),
            'con_diferencia' => $diferencias->filter(fn ($d) => $d !== 0)->count(),
            'sobrantes'      => (int) $diferencias->filter(fn ($d) => $d > 0)->sum(),
            'faltantes'      => (int) -$diferencias->filter(fn ($d) => $d < 0)->sum(),
        ];
    }

    /**
     * Aplica en un solo paso: la existencia de cada variante contada queda
     * igual a lo contado (contra la existencia de ESTE momento, por si hubo
     * ventas mientras se contaba). Los no contados no se tocan.
     */
    public function aplicar(int $conteoId): ConteoInventario
    {
        $this->exigir(PermisoModuloService::INVENTARIO_CONTEO);
        $this->exigir(PermisoModuloService::STOCK_AJUSTAR);
        $staff = $this->contexto->staff();

        return DB::transaction(function () use ($conteoId, $staff) {
            $conteo = ConteoInventario::query()->lockForUpdate()->find($conteoId)
                ?? throw new OperacionInvalidaException('No se encontró ese conteo.', 404);
            if (! $conteo->estaAbierto()) {
                throw new OperacionInvalidaException('Este conteo ya se aplicó o se canceló.', 409);
            }

            $ajustes = 0;
            $piezas = 0;
            foreach ($conteo->renglones()->whereNotNull('contado')->get() as $renglon) {
                $actual = (int) StockLocal::query()->where('sucursal_id', $conteo->sucursal_id)->where('variante_id', $renglon->variante_id)->value('cantidad_disponible');
                $delta = (int) $renglon->contado - $actual;

                if ($delta !== 0) {
                    $this->stock->registrarAjuste($renglon->variante_id, $delta > 0 ? 'ajuste_positivo' : 'ajuste_negativo', abs($delta), 'Inventario físico #'.$conteo->id);
                    $ajustes++;
                    $piezas += $delta;
                }
                $renglon->ajuste_aplicado = $delta;
                $renglon->save();
            }

            $conteo->estado = 'aplicado';
            $conteo->aplicado_por_staff_id = $staff?->id;
            $conteo->aplicado_at = now();
            $conteo->save();

            $this->auditoria->ejecutar('inventario.conteo_aplicado', 'conteo_inventario', (int) $conteo->id, ['estado' => 'abierto'],
                ['estado' => 'aplicado', 'ajustes' => $ajustes, 'piezas_netas' => $piezas]);

            return $conteo;
        });
    }

    public function cancelar(int $conteoId): ConteoInventario
    {
        $this->exigir(PermisoModuloService::INVENTARIO_CONTEO);
        $conteo = $this->abierto($conteoId);
        $conteo->update(['estado' => 'cancelado']);
        $this->auditoria->ejecutar('inventario.conteo_cancelado', 'conteo_inventario', (int) $conteo->id, ['estado' => 'abierto'], ['estado' => 'cancelado']);

        return $conteo;
    }

    private function abierto(int $conteoId): ConteoInventario
    {
        $conteo = ConteoInventario::query()->find($conteoId) ?? throw new OperacionInvalidaException('No se encontró ese conteo.', 404);
        if (! $conteo->estaAbierto()) {
            throw new OperacionInvalidaException('Este conteo ya se aplicó o se canceló.', 409);
        }

        return $conteo;
    }

    private function exigir(string $permiso): void
    {
        if (! $this->permisos->puede(auth()->user(), $permiso)) {
            throw new OperacionInvalidaException(MensajeError::SIN_PERMISO, 403);
        }
    }
}
