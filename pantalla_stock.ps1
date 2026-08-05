# ============================================================
#  FootwearPoint - Paquete C / E6: pantalla de Stock local
#  1) Componente Livewire  components\stock\<rayo>index.blade.php
#  2) Item de menu en layouts\panel.blade.php  (archivo COMPARTIDO)
#  3) Ruta en routes\web.php                   (archivo COMPARTIDO)
#
#  USO: guardar en C:\xampp\htdocs\footwearpoint y correr:
#     powershell -ExecutionPolicy Bypass -File .\pantalla_stock.ps1
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
    [System.IO.File]::WriteAllText((Join-Path (Get-Location) $ruta), $texto, $utf8SinBom)
    Write-Host "escrito   $ruta" -ForegroundColor Green
}

# El nombre del archivo lleva el emoji de rayo, igual que los componentes
# del resto del equipo. Se construye por codigo para que no dependa de
# como PowerShell interprete la codificacion de este script.
$rayo = [char]0x26A1

# ------------------------------------------------------------
# 1. Componente Livewire
# ------------------------------------------------------------

$componente = @'
<?php

use App\Exceptions\OperacionInvalidaException;
use App\Models\Variante;
use App\Services\Stock\StockService;
use App\Support\Tenant;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts.panel')] #[Title('Stock local — FootwearPoint')] class extends Component
{
    // Entrada de mercancía (E6-01)
    public string $entrada_variante_id = '';
    public string $entrada_cantidad = '';
    public string $entrada_motivo = '';

    // Ajuste manual (E6-02): el motivo es obligatorio
    public string $ajuste_variante_id = '';
    public string $ajuste_tipo = 'ajuste_negativo';
    public string $ajuste_cantidad = '';
    public string $ajuste_motivo = '';

    public string $busqueda = '';

    public string $mensaje = '';
    public string $errorMsg = '';

    public function mount()
    {
        if (! Auth::check()) {
            return $this->redirect(route('login'), navigate: true);
        }

        if (Tenant::id() === null) {
            abort(403, 'No se pudo determinar la distribuidora.');
        }
    }

    /**
     * Toda la lógica vive en StockService, el mismo que usa la API
     * (convención 1.5: la vista muestra, el servicio decide).
     */
    public function getExistenciasProperty()
    {
        try {
            $query = app(StockService::class)->consultar();

            if ($this->busqueda !== '') {
                $termino = $this->busqueda;
                $query->whereHas('variante', fn ($q) => $q->where('sku', 'like', "%{$termino}%"));
            }

            return $query->get();
        } catch (OperacionInvalidaException $e) {
            return collect();
        }
    }

    public function getVariantesProperty()
    {
        return Variante::query()
            ->orderBy('sku')
            ->get(['id', 'sku', 'producto_id']);
    }

    public function registrarEntrada(StockService $stock)
    {
        $this->mensaje = '';
        $this->errorMsg = '';

        $this->validate([
            'entrada_variante_id' => ['required', 'integer', 'min:1'],
            'entrada_cantidad' => ['required', 'integer', 'min:1'],
            'entrada_motivo' => ['nullable', 'string', 'max:300'],
        ], [
            'entrada_variante_id.required' => 'Selecciona una variante.',
            'entrada_cantidad.required' => 'Indica cuántas piezas entraron.',
            'entrada_cantidad.min' => 'La cantidad debe ser mayor que cero.',
        ]);

        try {
            $movimiento = $stock->registrarEntrada(
                (int) $this->entrada_variante_id,
                (int) $this->entrada_cantidad,
                $this->entrada_motivo !== '' ? $this->entrada_motivo : null,
            );

            $this->mensaje = 'Entrada registrada. Existencia: '
                . $movimiento->existencia_anterior . ' → ' . $movimiento->existencia_posterior . '.';

            $this->reset(['entrada_variante_id', 'entrada_cantidad', 'entrada_motivo']);
        } catch (OperacionInvalidaException $e) {
            $this->errorMsg = $e->getMessage();
        } catch (\Throwable $e) {
            $this->errorMsg = 'No se pudo registrar la entrada. Revisa los datos e intenta de nuevo.';
        }
    }

    public function registrarAjuste(StockService $stock)
    {
        $this->mensaje = '';
        $this->errorMsg = '';

        $this->validate([
            'ajuste_variante_id' => ['required', 'integer', 'min:1'],
            'ajuste_tipo' => ['required', 'in:ajuste_positivo,ajuste_negativo'],
            'ajuste_cantidad' => ['required', 'integer', 'min:1'],
            'ajuste_motivo' => ['required', 'string', 'max:300'],
        ], [
            'ajuste_variante_id.required' => 'Selecciona una variante.',
            'ajuste_cantidad.required' => 'Indica cuántas piezas ajustar.',
            'ajuste_motivo.required' => 'Todo ajuste manual necesita un motivo (merma, corrección, etc.).',
        ]);

        try {
            $movimiento = $stock->registrarAjuste(
                (int) $this->ajuste_variante_id,
                $this->ajuste_tipo,
                (int) $this->ajuste_cantidad,
                $this->ajuste_motivo,
            );

            $this->mensaje = 'Ajuste registrado. Existencia: '
                . $movimiento->existencia_anterior . ' → ' . $movimiento->existencia_posterior . '.';

            $this->reset(['ajuste_variante_id', 'ajuste_cantidad', 'ajuste_motivo']);
            $this->ajuste_tipo = 'ajuste_negativo';
        } catch (OperacionInvalidaException $e) {
            $this->errorMsg = $e->getMessage();
        } catch (\Throwable $e) {
            $this->errorMsg = 'No se pudo registrar el ajuste. Revisa los datos e intenta de nuevo.';
        }
    }
};
?>

<div>
    <div class="mb-6">
        <h2 class="text-2xl font-bold text-slate-900">Stock local</h2>
        <p class="text-sm text-slate-500 mt-1">
            Entradas de mercancía, existencias por variante y ajustes manuales de la sucursal principal.
        </p>
    </div>

    @if ($mensaje)
        <div class="mb-4 rounded-lg border border-green-200 bg-green-50 text-green-800 px-4 py-3 text-sm">
            {{ $mensaje }}
        </div>
    @endif

    @if ($errorMsg)
        <div class="mb-4 rounded-lg border border-red-200 bg-red-50 text-red-800 px-4 py-3 text-sm">
            {{ $errorMsg }}
        </div>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">

        {{-- Entrada de mercancía --}}
        <div class="bg-white rounded-xl border border-slate-200 shadow-sm p-5">
            <h3 class="text-sm font-semibold text-slate-800 mb-4">Registrar entrada</h3>

            <form wire:submit="registrarEntrada" class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Variante</label>
                    <select wire:model="entrada_variante_id"
                            class="w-full rounded-lg border-slate-300 text-sm focus:border-marca-primario focus:ring-marca-primario">
                        <option value="">— Seleccionar variante —</option>
                        @foreach ($this->variantes as $variante)
                            <option value="{{ $variante->id }}">{{ $variante->sku }}</option>
                        @endforeach
                    </select>
                    @error('entrada_variante_id') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Cantidad</label>
                    <input type="number" min="1" step="1" wire:model="entrada_cantidad"
                           class="w-full rounded-lg border-slate-300 text-sm focus:border-marca-primario focus:ring-marca-primario"
                           placeholder="10">
                    @error('entrada_cantidad') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Motivo (opcional)</label>
                    <input type="text" maxlength="300" wire:model="entrada_motivo"
                           class="w-full rounded-lg border-slate-300 text-sm focus:border-marca-primario focus:ring-marca-primario"
                           placeholder="Ej. compra a fábrica">
                </div>

                <button type="submit"
                        class="rounded-lg bg-marca-primario text-white px-4 py-2 text-sm font-medium hover:bg-blue-700">
                    Registrar entrada
                </button>
            </form>
        </div>

        {{-- Ajuste manual --}}
        <div class="bg-white rounded-xl border border-slate-200 shadow-sm p-5">
            <h3 class="text-sm font-semibold text-slate-800 mb-4">Ajuste manual</h3>

            <form wire:submit="registrarAjuste" class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Variante</label>
                    <select wire:model="ajuste_variante_id"
                            class="w-full rounded-lg border-slate-300 text-sm focus:border-marca-primario focus:ring-marca-primario">
                        <option value="">— Seleccionar variante —</option>
                        @foreach ($this->variantes as $variante)
                            <option value="{{ $variante->id }}">{{ $variante->sku }}</option>
                        @endforeach
                    </select>
                    @error('ajuste_variante_id') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1">Tipo</label>
                        <select wire:model="ajuste_tipo"
                                class="w-full rounded-lg border-slate-300 text-sm focus:border-marca-primario focus:ring-marca-primario">
                            <option value="ajuste_negativo">Descontar</option>
                            <option value="ajuste_positivo">Agregar</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1">Cantidad</label>
                        <input type="number" min="1" step="1" wire:model="ajuste_cantidad"
                               class="w-full rounded-lg border-slate-300 text-sm focus:border-marca-primario focus:ring-marca-primario"
                               placeholder="1">
                        @error('ajuste_cantidad') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Motivo</label>
                    <input type="text" maxlength="300" wire:model="ajuste_motivo"
                           class="w-full rounded-lg border-slate-300 text-sm focus:border-marca-primario focus:ring-marca-primario"
                           placeholder="Ej. merma, corrección de conteo">
                    @error('ajuste_motivo') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>

                <button type="submit"
                        class="rounded-lg bg-marca-oscuro text-white px-4 py-2 text-sm font-medium hover:opacity-90">
                    Registrar ajuste
                </button>
            </form>
        </div>
    </div>

    {{-- Existencias --}}
    <div class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
        <div class="px-5 py-3 border-b border-slate-100 flex flex-wrap items-center gap-3 justify-between">
            <h3 class="text-sm font-semibold text-slate-800">Existencias</h3>
            <input type="search" wire:model.live.debounce.300ms="busqueda"
                   class="rounded-lg border-slate-300 text-sm focus:border-marca-primario focus:ring-marca-primario"
                   placeholder="Buscar por SKU…">
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-slate-50 text-slate-600">
                    <tr>
                        <th class="text-left font-medium px-4 py-3">SKU</th>
                        <th class="text-left font-medium px-4 py-3">Producto</th>
                        <th class="text-right font-medium px-4 py-3">Disponible</th>
                        <th class="text-right font-medium px-4 py-3">Mínimo</th>
                        <th class="text-left font-medium px-4 py-3">Actualizado</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($this->existencias as $existencia)
                        <tr class="hover:bg-slate-50">
                            <td class="px-4 py-3 font-mono text-xs">
                                {{ $existencia->variante?->sku ?? '—' }}
                            </td>
                            <td class="px-4 py-3 text-slate-700">
                                {{ $existencia->variante?->producto?->nombre ?? '—' }}
                            </td>
                            <td class="px-4 py-3 text-right font-medium
                                {{ (int) $existencia->cantidad_disponible === 0 ? 'text-red-600' : 'text-slate-900' }}">
                                {{ (int) $existencia->cantidad_disponible }}
                            </td>
                            <td class="px-4 py-3 text-right text-slate-500">
                                {{ (int) $existencia->stock_minimo }}
                            </td>
                            <td class="px-4 py-3 text-slate-600">
                                {{ optional($existencia->updated_at)->format('d/m/Y H:i') ?? '—' }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-4 py-8 text-center text-slate-500">
                                No hay existencias registradas todavía.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
'@

Escribir "resources\views\components\stock\$($rayo)index.blade.php" $componente

# ------------------------------------------------------------
# 2. Item de menu en el layout compartido
# ------------------------------------------------------------

$rutaPanel = Join-Path (Get-Location) "resources\views\layouts\panel.blade.php"
$panel = [System.IO.File]::ReadAllText($rutaPanel)

if ($panel -notmatch "stock\.index") {
    $ancla = "                <a href=`"{{ route('vales.index') }}`""
    $itemStock = @"
                <a href="{{ route('stock.index') }}"
                    class="block rounded-lg px-3 py-2 text-sm hover:bg-white/10 {{ request()->routeIs('stock.*') ? 'bg-white/15' : '' }}">
                    Stock
                </a>
"@

    if ($panel.Contains($ancla)) {
        $panel = $panel.Replace($ancla, $itemStock + $ancla)
        $utf8SinBom = New-Object System.Text.UTF8Encoding($false)
        [System.IO.File]::WriteAllText($rutaPanel, $panel, $utf8SinBom)
        Write-Host "actualizado  resources\layouts\panel.blade.php (item Stock agregado)" -ForegroundColor Green
    } else {
        Write-Host "AVISO: no se encontro el enlace de Vales en panel.blade.php." -ForegroundColor Yellow
        Write-Host "       Agrega el item de menu a mano; el resto si quedo listo." -ForegroundColor Yellow
    }
} else {
    Write-Host "sin cambios  panel.blade.php (el item Stock ya estaba)" -ForegroundColor DarkGray
}

# ------------------------------------------------------------
# 3. Ruta web (archivo compartido: se agrega, no se reescribe)
# ------------------------------------------------------------

$rutaWeb = Join-Path (Get-Location) "routes\web.php"
$web = [System.IO.File]::ReadAllText($rutaWeb)

if ($web -notmatch "stock\.index") {
    $bloque = "`r`n// Paquete C - stock local, punto de venta y ciclos de compra`r`n" +
              "Route::livewire('/stock', 'stock.index')`r`n" +
              "    ->name('stock.index')`r`n" +
              "    ->middleware('auth');`r`n"

    $utf8SinBom = New-Object System.Text.UTF8Encoding($false)
    [System.IO.File]::WriteAllText($rutaWeb, $web.TrimEnd() + "`r`n" + $bloque, $utf8SinBom)
    Write-Host "actualizado  routes\web.php (ruta de stock agregada)" -ForegroundColor Green
} else {
    Write-Host "sin cambios  routes\web.php (la ruta ya estaba)" -ForegroundColor DarkGray
}

Write-Host ""
Write-Host "Listo. Ahora corre:" -ForegroundColor Cyan
Write-Host "  npm run build"
Write-Host "  php artisan optimize:clear"
Write-Host "  php artisan serve"
Write-Host ""
Write-Host "Y entra a http://localhost:8000/login con empleado@calzadosramirez.test / password" -ForegroundColor Cyan
