# ============================================================
#  FootwearPoint - Paquete C (E6 Stock local + E7 Venta directa)
#  Crea los 20 archivos del paquete y agrega los require a routes/api.php
#
#  USO: guardar en C:\xampp\htdocs\footwearpoint y correr:
#     powershell -ExecutionPolicy Bypass -File .\crear_paquete_c.ps1
# ============================================================

if (-not (Test-Path "artisan")) {
    Write-Host "ERROR: corre este script desde la raiz del proyecto (donde esta 'artisan')." -ForegroundColor Red
    exit 1
}

function Escribir($ruta, $texto) {
    $dir = Split-Path -Parent $ruta
    if ($dir -and -not (Test-Path $dir)) {
        New-Item -ItemType Directory -Force -Path $dir | Out-Null
    }
    $utf8SinBom = New-Object System.Text.UTF8Encoding($false)
    $rutaAbsoluta = Join-Path (Get-Location) $ruta
    [System.IO.File]::WriteAllText($rutaAbsoluta, $texto, $utf8SinBom)
    Write-Host "creado   $ruta" -ForegroundColor Green
}

# ------------------------------------------------------------
# 1. Nucleo compartido
# ------------------------------------------------------------

Escribir "app\Support\ContextoOperativo.php" @'
<?php

namespace App\Support;

use App\Exceptions\OperacionInvalidaException;
use App\Models\DistribuidoraStaff;
use App\Models\Sucursal;
use Illuminate\Support\Facades\Auth;

/**
 * Resuelve el contexto operativo (staff, distribuidora y sucursal principal)
 * SIEMPRE a partir del usuario autenticado, nunca de la peticion.
 * Convencion 1.8 del Plan de Tareas de Programacion del MVP.
 *
 * Complementa a App\Support\Tenant: Tenant da el id de la distribuidora;
 * esta clase da ademas la fila de staff (para registrado_por_staff_id) y
 * la sucursal principal.
 */
class ContextoOperativo
{
    private ?DistribuidoraStaff $staff = null;
    private ?Sucursal $sucursal = null;

    public function staff(): DistribuidoraStaff
    {
        if ($this->staff !== null) {
            return $this->staff;
        }

        $usuarioId = Auth::id();

        if ($usuarioId === null) {
            throw new OperacionInvalidaException('No hay un usuario autenticado.', 401);
        }

        // withoutGlobalScopes: esta es la consulta que resuelve el tenant,
        // por lo que no puede filtrarse a si misma por distribuidora_id.
        $staff = DistribuidoraStaff::withoutGlobalScopes()
            ->where('usuario_id', $usuarioId)
            ->where('estado', 'activo')
            ->orderBy('id')
            ->first();

        if ($staff === null) {
            throw new OperacionInvalidaException(
                'El usuario autenticado no es staff activo de ninguna distribuidora.',
                403
            );
        }

        return $this->staff = $staff;
    }

    public function distribuidoraId(): int
    {
        return (int) $this->staff()->distribuidora_id;
    }

    /**
     * El MVP opera con una sola sucursal (seccion 2 del Plan de Tareas).
     */
    public function sucursalPrincipal(): Sucursal
    {
        if ($this->sucursal !== null) {
            return $this->sucursal;
        }

        $sucursal = Sucursal::query()
            ->where('distribuidora_id', $this->distribuidoraId())
            ->where('es_principal', true)
            ->where('activa', true)
            ->first();

        if ($sucursal === null) {
            throw new OperacionInvalidaException(
                'La distribuidora no tiene una sucursal principal activa.',
                409
            );
        }

        return $this->sucursal = $sucursal;
    }
}
'@

Escribir "app\Exceptions\OperacionInvalidaException.php" @'
<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Respeta el formato de error de la seccion 1.7 sin tocar bootstrap/app.php:
 * Laravel llama solo al metodo render() de la excepcion.
 */
class OperacionInvalidaException extends Exception
{
    public function __construct(
        string $message,
        private int $estadoHttp = 409,
        private array $errores = []
    ) {
        parent::__construct($message);
    }

    public function render(Request $request): JsonResponse
    {
        $cuerpo = ['message' => $this->getMessage()];

        if ($this->errores !== []) {
            $cuerpo['errors'] = $this->errores;
        }

        return response()->json($cuerpo, $this->estadoHttp);
    }
}
'@

# ------------------------------------------------------------
# 2. E6 - Stock local
# ------------------------------------------------------------

Escribir "app\Services\Stock\StockService.php" @'
<?php

namespace App\Services\Stock;

use App\Exceptions\OperacionInvalidaException;
use App\Models\MovimientoStock;
use App\Models\StockLocal;
use App\Models\Variante;
use App\Support\ContextoOperativo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class StockService
{
    public function __construct(private ContextoOperativo $contexto)
    {
    }

    /**
     * E6-02 - Consulta de existencias por variante.
     */
    public function consultar(?int $varianteId = null): Builder
    {
        $query = StockLocal::query()
            ->where('distribuidora_id', $this->contexto->distribuidoraId())
            ->where('sucursal_id', $this->contexto->sucursalPrincipal()->id)
            ->with('variante');

        if ($varianteId !== null) {
            $this->verificarVarianteDelTenant($varianteId);
            $query->where('variante_id', $varianteId);
        }

        return $query->orderBy('variante_id');
    }

    /**
     * E6-01 - Entrada de stock. Crea la fila de stock_local si es la primera
     * entrada de esa variante en la sucursal.
     */
    public function registrarEntrada(int $varianteId, int $cantidad, ?string $motivo = null): MovimientoStock
    {
        return DB::transaction(function () use ($varianteId, $cantidad, $motivo) {
            $stock = $this->stockBloqueado($varianteId, permitirCreacion: true);

            $anterior = (int) $stock->cantidad_disponible;
            $posterior = $anterior + $cantidad;

            $this->guardarExistencia($stock, $posterior);

            return $this->registrarMovimiento($stock, 'entrada', $cantidad, $anterior, $posterior, $motivo);
        });
    }

    /**
     * E6-02 - Ajuste manual con motivo obligatorio (merma, correccion).
     */
    public function registrarAjuste(int $varianteId, string $tipo, int $cantidad, string $motivo): MovimientoStock
    {
        if (! in_array($tipo, ['ajuste_positivo', 'ajuste_negativo'], true)) {
            throw new OperacionInvalidaException('Tipo de ajuste no valido.', 422);
        }

        return DB::transaction(function () use ($varianteId, $tipo, $cantidad, $motivo) {
            $esPositivo = $tipo === 'ajuste_positivo';

            $stock = $this->stockBloqueado($varianteId, permitirCreacion: $esPositivo);

            $anterior = (int) $stock->cantidad_disponible;
            $posterior = $esPositivo ? $anterior + $cantidad : $anterior - $cantidad;

            if ($posterior < 0) {
                throw new OperacionInvalidaException(
                    "El ajuste dejaria la existencia en negativo. Existencia actual: {$anterior}.",
                    409,
                    ['cantidad' => ['No puedes descontar mas piezas de las que hay registradas.']]
                );
            }

            $this->guardarExistencia($stock, $posterior);

            return $this->registrarMovimiento($stock, $tipo, $cantidad, $anterior, $posterior, $motivo);
        });
    }

    /**
     * Bloquea la fila de existencia para evitar que dos operaciones
     * simultaneas lean la misma existencia_anterior.
     */
    private function stockBloqueado(int $varianteId, bool $permitirCreacion): StockLocal
    {
        $this->verificarVarianteDelTenant($varianteId);

        $distribuidoraId = $this->contexto->distribuidoraId();
        $sucursalId = (int) $this->contexto->sucursalPrincipal()->id;

        $stock = StockLocal::query()
            ->where('distribuidora_id', $distribuidoraId)
            ->where('sucursal_id', $sucursalId)
            ->where('variante_id', $varianteId)
            ->lockForUpdate()
            ->first();

        if ($stock !== null) {
            return $stock;
        }

        if (! $permitirCreacion) {
            throw new OperacionInvalidaException(
                'Esta variante todavia no tiene existencia registrada en la sucursal.',
                409
            );
        }

        $stock = new StockLocal();
        $stock->timestamps = false;
        $stock->forceFill([
            'distribuidora_id' => $distribuidoraId,
            'sucursal_id' => $sucursalId,
            'variante_id' => $varianteId,
            'cantidad_disponible' => 0,
            'stock_minimo' => 0,
            'updated_at' => now(),
        ])->save();

        return $stock;
    }

    private function guardarExistencia(StockLocal $stock, int $existencia): void
    {
        $stock->timestamps = false;
        $stock->cantidad_disponible = $existencia;
        $stock->updated_at = now();
        $stock->save();
    }

    private function registrarMovimiento(
        StockLocal $stock,
        string $tipo,
        int $cantidad,
        int $anterior,
        int $posterior,
        ?string $motivo
    ): MovimientoStock {
        $movimiento = new MovimientoStock();
        $movimiento->timestamps = false;
        $movimiento->forceFill([
            'distribuidora_id' => $stock->distribuidora_id,
            'stock_local_id' => $stock->id,
            'tipo' => $tipo,
            'cantidad' => $cantidad,
            'existencia_anterior' => $anterior,
            'existencia_posterior' => $posterior,
            'venta_detalle_id' => null, // solo lo llena la venta directa (E7)
            'registrado_por_staff_id' => $this->contexto->staff()->id,
            'motivo' => $motivo,
            'created_at' => now(),
        ])->save();

        return $movimiento;
    }

    /**
     * Si la variante es de otra distribuidora, el Global Scope la deja fuera
     * y esto responde 404: nunca se expone que existe.
     */
    private function verificarVarianteDelTenant(int $varianteId): void
    {
        $existe = Variante::query()
            ->where('distribuidora_id', $this->contexto->distribuidoraId())
            ->whereKey($varianteId)
            ->exists();

        if (! $existe) {
            throw new OperacionInvalidaException('La variante no existe.', 404);
        }
    }
}
'@

Escribir "app\Http\Requests\Stock\IndexStockRequest.php" @'
<?php

namespace App\Http\Requests\Stock;

use Illuminate\Foundation\Http\FormRequest;

class IndexStockRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // La autorizacion real vive en StockLocalPolicy.
    }

    public function rules(): array
    {
        return [
            'variante_id' => ['nullable', 'integer', 'min:1'],
            'por_pagina' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
'@

Escribir "app\Http\Requests\Stock\StoreEntradaStockRequest.php" @'
<?php

namespace App\Http\Requests\Stock;

use Illuminate\Foundation\Http\FormRequest;

class StoreEntradaStockRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'variante_id' => ['required', 'integer', 'min:1'],
            'cantidad' => ['required', 'integer', 'min:1'],
            'motivo' => ['nullable', 'string', 'max:300'],
        ];
    }

    public function messages(): array
    {
        return [
            'cantidad.min' => 'La cantidad de entrada debe ser mayor que cero.',
        ];
    }
}
'@

Escribir "app\Http\Requests\Stock\StoreAjusteStockRequest.php" @'
<?php

namespace App\Http\Requests\Stock;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAjusteStockRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'variante_id' => ['required', 'integer', 'min:1'],
            'tipo' => ['required', Rule::in(['ajuste_positivo', 'ajuste_negativo'])],
            'cantidad' => ['required', 'integer', 'min:1'],
            'motivo' => ['required', 'string', 'max:300'],
        ];
    }

    public function messages(): array
    {
        return [
            'motivo.required' => 'Todo ajuste manual necesita un motivo (merma, correccion, etc.).',
        ];
    }
}
'@

Escribir "app\Http\Resources\StockLocalResource.php" @'
<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StockLocalResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sucursal_id' => (int) $this->sucursal_id,
            'variante_id' => (int) $this->variante_id,
            'sku' => $this->whenLoaded('variante', fn () => $this->variante->sku),
            'cantidad_disponible' => (int) $this->cantidad_disponible,
            'stock_minimo' => (int) $this->stock_minimo,
            'actualizado_en' => $this->updated_at?->toIso8601String(),
        ];
    }
}
'@

Escribir "app\Http\Resources\MovimientoStockResource.php" @'
<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MovimientoStockResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'stock_local_id' => (int) $this->stock_local_id,
            'tipo' => $this->tipo,
            'cantidad' => (int) $this->cantidad,
            'existencia_anterior' => (int) $this->existencia_anterior,
            'existencia_posterior' => (int) $this->existencia_posterior,
            'motivo' => $this->motivo,
            'registrado_por_staff_id' => (int) $this->registrado_por_staff_id,
            'registrado_en' => $this->created_at?->toIso8601String(),
        ];
    }
}
'@

Escribir "app\Policies\StockLocalPolicy.php" @'
<?php

namespace App\Policies;

use App\Support\ContextoOperativo;
use Illuminate\Contracts\Auth\Authenticatable;
use Throwable;

class StockLocalPolicy
{
    public function viewAny(Authenticatable $usuario): bool
    {
        return $this->esStaffActivo();
    }

    public function create(Authenticatable $usuario): bool
    {
        return $this->esStaffActivo();
    }

    private function esStaffActivo(): bool
    {
        try {
            app(ContextoOperativo::class)->staff();

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
'@

Escribir "app\Http\Controllers\Api\StockController.php" @'
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Stock\IndexStockRequest;
use App\Http\Requests\Stock\StoreAjusteStockRequest;
use App\Http\Requests\Stock\StoreEntradaStockRequest;
use App\Http\Resources\MovimientoStockResource;
use App\Http\Resources\StockLocalResource;
use App\Models\StockLocal;
use App\Services\Stock\StockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class StockController extends Controller
{
    public function __construct(private StockService $stock)
    {
    }

    public function index(IndexStockRequest $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', StockLocal::class);

        $existencias = $this->stock
            ->consultar($request->filled('variante_id') ? $request->integer('variante_id') : null)
            ->paginate($request->filled('por_pagina') ? $request->integer('por_pagina') : 25);

        return StockLocalResource::collection($existencias);
    }

    public function storeEntrada(StoreEntradaStockRequest $request): JsonResponse
    {
        Gate::authorize('create', StockLocal::class);

        $movimiento = $this->stock->registrarEntrada(
            $request->integer('variante_id'),
            $request->integer('cantidad'),
            $request->input('motivo'),
        );

        return (new MovimientoStockResource($movimiento))
            ->additional(['message' => 'Entrada de stock registrada.'])
            ->response()
            ->setStatusCode(201);
    }

    public function storeAjuste(StoreAjusteStockRequest $request): JsonResponse
    {
        Gate::authorize('create', StockLocal::class);

        $movimiento = $this->stock->registrarAjuste(
            $request->integer('variante_id'),
            $request->string('tipo')->toString(),
            $request->integer('cantidad'),
            $request->string('motivo')->toString(),
        );

        return (new MovimientoStockResource($movimiento))
            ->additional(['message' => 'Ajuste de stock registrado.'])
            ->response()
            ->setStatusCode(201);
    }
}
'@

# ------------------------------------------------------------
# 3. E7 - Venta directa
# ------------------------------------------------------------

Escribir "app\Services\VentaDirecta\DescuentoStockVentaDirectaService.php" @'
<?php

namespace App\Services\VentaDirecta;

use App\Exceptions\OperacionInvalidaException;
use App\Models\MovimientoStock;
use App\Models\StockLocal;
use App\Models\VentaDirectaDetalle;
use Illuminate\Support\Facades\DB;

/**
 * SERVICIO EXCLUSIVO DE VENTA DIRECTA (E6-03 / E7-01).
 *
 * No debe invocarse desde el flujo de pedidos. Un pedido -de cliente directo
 * o de revendedor- NUNCA modifica stock_local, aunque la variante tenga
 * existencia disponible.
 */
class DescuentoStockVentaDirectaService
{
    /**
     * Bloquea la fila de existencia y valida que alcance, ANTES de escribir
     * cualquier cosa de la venta. Debe llamarse dentro de una transaccion.
     */
    public function bloquearYValidar(
        int $varianteId,
        int $cantidad,
        int $distribuidoraId,
        int $sucursalId
    ): StockLocal {
        $this->exigirTransaccion();

        $stock = StockLocal::query()
            ->where('distribuidora_id', $distribuidoraId)
            ->where('sucursal_id', $sucursalId)
            ->where('variante_id', $varianteId)
            ->lockForUpdate()
            ->first();

        $disponible = $stock !== null ? (int) $stock->cantidad_disponible : 0;

        if ($stock === null || $disponible < $cantidad) {
            throw new OperacionInvalidaException(
                'No hay existencia suficiente en stock local para completar la venta.',
                409,
                ['lineas' => [
                    "Variante {$varianteId}: existencia disponible {$disponible}, solicitada {$cantidad}.",
                ]]
            );
        }

        return $stock;
    }

    /**
     * Descuenta la existencia y deja el movimiento ligado a la linea de venta.
     */
    public function descontar(
        StockLocal $stock,
        int $cantidad,
        VentaDirectaDetalle $detalle,
        int $staffId
    ): MovimientoStock {
        $this->exigirTransaccion();

        $anterior = (int) $stock->cantidad_disponible;
        $posterior = $anterior - $cantidad;

        if ($posterior < 0) {
            throw new OperacionInvalidaException(
                'No hay existencia suficiente en stock local para completar la venta.',
                409
            );
        }

        $stock->timestamps = false;
        $stock->cantidad_disponible = $posterior;
        $stock->updated_at = now();
        $stock->save();

        $movimiento = new MovimientoStock();
        $movimiento->timestamps = false;
        $movimiento->forceFill([
            'distribuidora_id' => $stock->distribuidora_id,
            'stock_local_id' => $stock->id,
            'tipo' => 'venta',
            'cantidad' => $cantidad,
            'existencia_anterior' => $anterior,
            'existencia_posterior' => $posterior,
            'venta_detalle_id' => $detalle->id,
            'registrado_por_staff_id' => $staffId,
            'motivo' => null,
            'created_at' => now(),
        ])->save();

        return $movimiento;
    }

    private function exigirTransaccion(): void
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException(
                'El descuento de stock de venta directa debe ejecutarse dentro de una transaccion.'
            );
        }
    }
}
'@

Escribir "app\Services\VentaDirecta\ResultadoVentaDirecta.php" @'
<?php

namespace App\Services\VentaDirecta;

use App\Models\Pago;
use App\Models\VentaDirecta;

final class ResultadoVentaDirecta
{
    /**
     * @param array<int, \App\Models\VentaDirectaDetalle> $detalles
     */
    public function __construct(
        public readonly VentaDirecta $venta,
        public readonly array $detalles,
        public readonly Pago $pago,
    ) {
    }
}
'@

Escribir "app\Services\VentaDirecta\RegistrarVentaDirectaService.php" @'
<?php

namespace App\Services\VentaDirecta;

use App\Exceptions\OperacionInvalidaException;
use App\Models\ClienteDirecto;
use App\Models\Color;
use App\Models\Pago;
use App\Models\Producto;
use App\Models\ProductoCampana;
use App\Models\Talla;
use App\Models\Variante;
use App\Models\VentaDirecta;
use App\Models\VentaDirectaDetalle;
use App\Support\ContextoOperativo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class RegistrarVentaDirectaService
{
    public function __construct(
        private ContextoOperativo $contexto,
        private DescuentoStockVentaDirectaService $descuentoStock,
    ) {
    }

    /**
     * @param array<int, array{variante_id:int, producto_campana_id:int, cantidad:int}> $lineas
     * @param string $metodoPago valor del enum pagos.metodo
     */
    public function registrar(array $lineas, string $metodoPago, ?int $clienteDirectoId = null): ResultadoVentaDirecta
    {
        return DB::transaction(function () use ($lineas, $metodoPago, $clienteDirectoId) {
            $distribuidoraId = $this->contexto->distribuidoraId();
            $sucursalId = (int) $this->contexto->sucursalPrincipal()->id;
            $staffId = (int) $this->contexto->staff()->id;

            if ($clienteDirectoId !== null) {
                $this->verificarClienteDelTenant($clienteDirectoId, $distribuidoraId);
            }

            // 1) Resolver precios y BLOQUEAR todo el stock antes de escribir nada.
            //    Si una sola linea no alcanza, la transaccion muere sin dejar rastro.
            $preparadas = [];
            foreach ($lineas as $linea) {
                $preparadas[] = $this->prepararLinea($linea, $distribuidoraId, $sucursalId);
            }

            // 2) Totales. OJO: estos importes YA INCLUYEN IVA (confirmado por el equipo).
            //    chk_venta_totales exige total = subtotal - descuento, sin IVA en la ecuacion.
            $subtotal = round(array_sum(array_column($preparadas, 'subtotal')), 2);
            $descuento = 0.00; // decision confirmada: no se construyen descuentos este sprint
            $total = round($subtotal - $descuento, 2);

            // pagos.chk_pago_monto exige monto > 0: sin esto, el insert del pago
            // reventaria con un error de SQL en vez de un mensaje entendible.
            if ($total <= 0) {
                throw new OperacionInvalidaException(
                    'El total de la venta debe ser mayor que cero para poder registrar el cobro.',
                    409
                );
            }

            $venta = new VentaDirecta();
            $venta->timestamps = false;
            $venta->forceFill([
                'distribuidora_id' => $distribuidoraId,
                'sucursal_id' => $sucursalId,
                'cliente_directo_id' => $clienteDirectoId,
                'folio' => $this->siguienteFolio(VentaDirecta::query(), 'VD-', $distribuidoraId),
                'fecha_venta' => now(),
                'subtotal' => $subtotal,
                'descuento' => $descuento,
                'total' => $total,
                'estado' => 'completada', // E7-01: la venta se entrega de inmediato
                'registrada_por_staff_id' => $staffId,
                'created_at' => now(),
                'updated_at' => now(),
            ])->save();

            // 3) Detalle + descuento de stock, linea por linea.
            $detalles = [];
            foreach ($preparadas as $linea) {
                $detalle = new VentaDirectaDetalle();
                $detalle->timestamps = false; // la tabla no tiene created_at ni updated_at
                $detalle->forceFill([
                    'distribuidora_id' => $distribuidoraId,
                    'venta_directa_id' => $venta->id,
                    'stock_local_id' => $linea['stock']->id,
                    'producto_campana_id' => $linea['producto_campana_id'],
                    'variante_id' => $linea['variante_id'],
                    'producto_nombre' => $linea['producto_nombre'],
                    'modelo' => $linea['modelo'],
                    'talla' => $linea['talla'],
                    'color' => $linea['color'],
                    'cantidad' => $linea['cantidad'],
                    'precio_unitario' => $linea['precio_unitario'],
                    'subtotal' => $linea['subtotal'],
                ])->save();

                $this->descuentoStock->descontar(
                    $linea['stock'],
                    $linea['cantidad'],
                    $detalle,
                    $staffId
                );

                $detalles[] = $detalle;
            }

            // 4) Cobro de contado, dentro de la misma transaccion.
            $pago = $this->registrarPago($venta, $metodoPago, $staffId, $distribuidoraId);

            return new ResultadoVentaDirecta($venta, $detalles, $pago);
        });
    }

    /**
     * La venta directa es de contado: el pago se registra completo y aplicado.
     * chk_pago_origen exige exactamente uno de pedido_id / venta_directa_id.
     * chk_pago_direccion exige 'entrada' para todo tipo distinto de reembolso.
     */
    private function registrarPago(
        VentaDirecta $venta,
        string $metodoPago,
        int $staffId,
        int $distribuidoraId
    ): Pago {
        $pago = new Pago();
        $pago->timestamps = false; // la tabla no tiene updated_at
        $pago->forceFill([
            'distribuidora_id' => $distribuidoraId,
            'pedido_id' => null,
            'venta_directa_id' => $venta->id,
            'folio' => $this->siguienteFolio(Pago::query(), 'PG-', $distribuidoraId),
            'tipo' => 'venta_directa',
            'direccion' => 'entrada',
            'metodo' => $metodoPago,
            'monto' => (float) $venta->total,
            'fecha_pago' => now(),
            'referencia' => null,          // pendiente: el empleado captura referencia?
            'proveedor_pago' => null,      // solo aplicaria con Mercado Pago real (fuera de alcance)
            'referencia_externa' => null,
            'estado' => 'aplicado',
            'registrado_por_staff_id' => $staffId,
            'created_at' => now(),
        ])->save();

        return $pago;
    }

    /**
     * @param array{variante_id:int, producto_campana_id:int, cantidad:int} $linea
     */
    private function prepararLinea(array $linea, int $distribuidoraId, int $sucursalId): array
    {
        $varianteId = (int) $linea['variante_id'];
        $productoCampanaId = (int) $linea['producto_campana_id'];
        $cantidad = (int) $linea['cantidad'];

        $variante = Variante::query()
            ->where('distribuidora_id', $distribuidoraId)
            ->whereKey($varianteId)
            ->first();

        if ($variante === null) {
            throw new OperacionInvalidaException('La variante no existe.', 404);
        }

        $productoCampana = ProductoCampana::query()
            ->where('distribuidora_id', $distribuidoraId)
            ->whereKey($productoCampanaId)
            ->first();

        if ($productoCampana === null) {
            throw new OperacionInvalidaException('La publicacion de catalogo no existe.', 404);
        }

        if ((int) $productoCampana->producto_id !== (int) $variante->producto_id) {
            throw new OperacionInvalidaException(
                'La variante no pertenece al producto de esa publicacion de catalogo.',
                409
            );
        }

        // No se valida el estado de la campana: el stock fisico se puede vender
        // aunque la campana ya este finalizada, con su ultimo precio conocido.
        $producto = Producto::query()
            ->where('distribuidora_id', $distribuidoraId)
            ->whereKey($variante->producto_id)
            ->first();

        if ($producto === null) {
            throw new OperacionInvalidaException('El producto de la variante no existe.', 404);
        }

        // tallas y colores son catalogos GLOBALES: no llevan distribuidora_id.
        $talla = Talla::query()->whereKey($variante->talla_id)->first();
        $color = Color::query()->whereKey($variante->color_id)->first();

        $stock = $this->descuentoStock->bloquearYValidar(
            $varianteId,
            $cantidad,
            $distribuidoraId,
            $sucursalId
        );

        $precioUnitario = round((float) $productoCampana->precio_minorista_sugerido, 2);

        return [
            'stock' => $stock,
            'cantidad' => $cantidad,
            'variante_id' => $varianteId,
            'producto_campana_id' => $productoCampanaId,
            // Fotografia historica: se copia el texto, no la referencia,
            // para que la venta no cambie si el catalogo se edita despues.
            'producto_nombre' => (string) $producto->nombre,
            'modelo' => (string) $producto->modelo,
            'talla' => $talla !== null ? trim($talla->sistema . ' ' . $talla->valor) : '',
            'color' => (string) ($variante->nombre_color_comercial ?: ($color->nombre ?? '')),
            'precio_unitario' => $precioUnitario,
            'subtotal' => round($cantidad * $precioUnitario, 2),
        ];
    }

    private function verificarClienteDelTenant(int $clienteDirectoId, int $distribuidoraId): void
    {
        $existe = ClienteDirecto::query()
            ->where('distribuidora_id', $distribuidoraId)
            ->whereKey($clienteDirectoId)
            ->exists();

        if (! $existe) {
            throw new OperacionInvalidaException('El cliente directo no existe.', 404);
        }
    }

    /**
     * DECISION PROVISIONAL confirmada por el lider: ningun archivo define el
     * formato de folio, ni de venta directa ni de pago. Consecutivo por
     * distribuidora y por ano, calculado dentro de la transaccion con bloqueo
     * para que dos cajas simultaneas no repitan folio.
     */
    private function siguienteFolio(Builder $query, string $tipo, int $distribuidoraId): string
    {
        $prefijo = $tipo . now()->format('Y') . '-';

        $ultimo = $query
            ->where('distribuidora_id', $distribuidoraId)
            ->where('folio', 'like', $prefijo . '%')
            ->orderByDesc('folio')
            ->lockForUpdate()
            ->value('folio');

        $consecutivo = $ultimo !== null
            ? ((int) substr((string) $ultimo, strlen($prefijo))) + 1
            : 1;

        return $prefijo . str_pad((string) $consecutivo, 6, '0', STR_PAD_LEFT);
    }
}
'@

Escribir "app\Http\Requests\VentaDirecta\StoreVentaDirectaRequest.php" @'
<?php

namespace App\Http\Requests\VentaDirecta;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreVentaDirectaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // La autorizacion real vive en VentaDirectaPolicy.
    }

    public function rules(): array
    {
        return [
            'cliente_directo_id' => ['nullable', 'integer', 'min:1'],

            // Valores del enum real de pagos.metodo.
            'metodo_pago' => ['required', Rule::in([
                'efectivo', 'transferencia', 'tarjeta', 'mercado_pago', 'otro',
            ])],

            'lineas' => ['required', 'array', 'min:1'],
            'lineas.*.variante_id' => ['required', 'integer', 'min:1', 'distinct'],
            'lineas.*.producto_campana_id' => ['required', 'integer', 'min:1'],
            'lineas.*.cantidad' => ['required', 'integer', 'min:1'],
        ];
    }

    public function messages(): array
    {
        return [
            'metodo_pago.required' => 'Indica con que metodo se cobro la venta.',
            'lineas.required' => 'La venta necesita al menos un producto.',
            'lineas.*.variante_id.distinct' => 'Cada variante debe aparecer una sola vez; sumala en la cantidad.',
            'lineas.*.cantidad.min' => 'La cantidad vendida debe ser mayor que cero.',
        ];
    }
}
'@

Escribir "app\Http\Resources\VentaDirectaResource.php" @'
<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Recibe un App\Services\VentaDirecta\ResultadoVentaDirecta.
 */
class VentaDirectaResource extends JsonResource
{
    /** Tasa documentada en el Plan de Tareas. El modelo no tiene columna de tasa. */
    private const TASA_IVA = 0.16;

    public function toArray(Request $request): array
    {
        $venta = $this->venta;
        $pago = $this->pago;
        $total = (float) $venta->total;

        // OJO: base_gravable e IVA son SOLO para mostrar. No corresponden a
        // ninguna columna: ventas_directas.subtotal ya incluye IVA.
        $baseGravable = round($total / (1 + self::TASA_IVA), 2);

        return [
            'id' => $venta->id,
            'folio' => $venta->folio,
            'fecha_venta' => $venta->fecha_venta?->toIso8601String(),
            'sucursal_id' => (int) $venta->sucursal_id,
            'cliente_directo_id' => $venta->cliente_directo_id !== null
                ? (int) $venta->cliente_directo_id
                : null,
            'estado' => $venta->estado,
            'registrada_por_staff_id' => (int) $venta->registrada_por_staff_id,

            // Importes reales de la base de datos (IVA incluido).
            'subtotal' => (float) $venta->subtotal,
            'descuento' => (float) $venta->descuento,
            'total' => $total,

            // Desglose calculado, nunca almacenado.
            'desglose_iva' => [
                'etiqueta_base' => 'Base gravable',
                'base_gravable' => $baseGravable,
                'etiqueta_iva' => 'IVA (16%)',
                'iva' => round($total - $baseGravable, 2),
            ],

            'pago' => [
                'id' => $pago->id,
                'folio' => $pago->folio,
                'tipo' => $pago->tipo,
                'direccion' => $pago->direccion,
                'metodo' => $pago->metodo,
                'monto' => (float) $pago->monto,
                'estado' => $pago->estado,
                'fecha_pago' => $pago->fecha_pago?->toIso8601String(),
            ],

            'lineas' => VentaDirectaDetalleResource::collection($this->detalles),
        ];
    }
}
'@

Escribir "app\Http\Resources\VentaDirectaDetalleResource.php" @'
<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VentaDirectaDetalleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'variante_id' => (int) $this->variante_id,
            'producto_campana_id' => $this->producto_campana_id !== null
                ? (int) $this->producto_campana_id
                : null,
            'producto_nombre' => $this->producto_nombre,
            'modelo' => $this->modelo,
            'talla' => $this->talla,
            'color' => $this->color,
            'cantidad' => (int) $this->cantidad,
            'precio_unitario' => (float) $this->precio_unitario,
            'subtotal' => (float) $this->subtotal,
        ];
    }
}
'@

Escribir "app\Policies\VentaDirectaPolicy.php" @'
<?php

namespace App\Policies;

use App\Support\ContextoOperativo;
use Illuminate\Contracts\Auth\Authenticatable;
use Throwable;

class VentaDirectaPolicy
{
    public function create(Authenticatable $usuario): bool
    {
        try {
            app(ContextoOperativo::class)->staff();

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
'@

Escribir "app\Http\Controllers\Api\VentaDirectaController.php" @'
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\VentaDirecta\StoreVentaDirectaRequest;
use App\Http\Resources\VentaDirectaResource;
use App\Models\VentaDirecta;
use App\Services\VentaDirecta\RegistrarVentaDirectaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class VentaDirectaController extends Controller
{
    public function __construct(private RegistrarVentaDirectaService $ventas)
    {
    }

    public function store(StoreVentaDirectaRequest $request): JsonResponse
    {
        Gate::authorize('create', VentaDirecta::class);

        $resultado = $this->ventas->registrar(
            $request->input('lineas'),
            $request->string('metodo_pago')->toString(),
            $request->filled('cliente_directo_id') ? $request->integer('cliente_directo_id') : null,
        );

        return (new VentaDirectaResource($resultado))
            ->additional(['message' => 'Venta directa registrada.'])
            ->response()
            ->setStatusCode(201);
    }
}
'@

# ------------------------------------------------------------
# 4. Rutas
# ------------------------------------------------------------

Escribir "routes\api\stock.php" @'
<?php

use App\Http\Controllers\Api\StockController;
use Illuminate\Support\Facades\Route;

/*
| Paquete C - Stock local (E6)
| Mismo stack de middleware que routes/api/distribuidora.php del Paquete B.
| Recordatorio para quien mergee: agregar en routes/api.php
|   require __DIR__.'/api/stock.php';
*/

Route::prefix('stock')
    ->middleware(['auth:sanctum', 'tenant.team', 'role:admin_distribuidora|empleado'])
    ->group(function () {
        Route::get('/', [StockController::class, 'index']);
        Route::post('entradas', [StockController::class, 'storeEntrada']);
        Route::post('ajustes', [StockController::class, 'storeAjuste']);
    });
'@

Escribir "routes\api\ventas-directas.php" @'
<?php

use App\Http\Controllers\Api\VentaDirectaController;
use Illuminate\Support\Facades\Route;

/*
| Paquete C - Venta directa (E7)
| Recordatorio para quien mergee: agregar en routes/api.php
|   require __DIR__.'/api/ventas-directas.php';
*/

Route::middleware(['auth:sanctum', 'tenant.team', 'role:admin_distribuidora|empleado'])
    ->group(function () {
        Route::post('ventas-directas', [VentaDirectaController::class, 'store']);
    });
'@

# ------------------------------------------------------------
# 5. Agregar los require a routes/api.php (sin sobrescribir el archivo)
# ------------------------------------------------------------

$rutaApi = Join-Path (Get-Location) "routes\api.php"
$contenidoApi = [System.IO.File]::ReadAllText($rutaApi)

if ($contenidoApi -notmatch "api/stock\.php") {
    $bloque = "`r`n// Paquete C - stock local, venta directa y ciclos de compra`r`n" +
              "require __DIR__.'/api/stock.php';`r`n" +
              "require __DIR__.'/api/ventas-directas.php';`r`n"
    $utf8SinBom = New-Object System.Text.UTF8Encoding($false)
    [System.IO.File]::WriteAllText($rutaApi, $contenidoApi.TrimEnd() + "`r`n" + $bloque, $utf8SinBom)
    Write-Host "actualizado  routes\api.php (require agregados)" -ForegroundColor Green
} else {
    Write-Host "sin cambios  routes\api.php (los require ya estaban)" -ForegroundColor DarkGray
}

Write-Host ""
Write-Host "Listo. Ahora corre:" -ForegroundColor Cyan
Write-Host "  php artisan optimize:clear"
Write-Host "  php artisan route:list --path=api"
Write-Host "  php artisan test --filter=StockEndpointsTest"
Write-Host "  git add . ; git commit -m 'E6 y E7: agrega stock local y venta directa'"
