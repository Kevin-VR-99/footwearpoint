<?php

use App\Services\Auth\PermisoModuloService;
use App\Services\Cliente\ImportarClientesAction;
use App\Support\MensajeError;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Importar clientes directos desde CSV o Excel: vista previa con los errores
 * de cada renglón, importar solo los válidos y bajar el reporte de errores.
 * Permiso fino clientes.importar (apagado por omisión para empleados).
 */
new #[Layout('layouts.panel')] #[Title('Importar clientes — FootwearPoint')] class extends Component {
    use WithFileUploads;

    #[Validate('required|file|max:5120|mimes:csv,txt,xlsx,xls')]
    public $archivo;

    public array $filas = [];
    public string $mensaje = '';
    public string $errorMsg = '';

    public function mount(): void
    {
        abort_unless(app(PermisoModuloService::class)->puede(auth()->user(), PermisoModuloService::CLIENTES_IMPORTAR), 403);
    }

    public function updatedArchivo(): void
    {
        $this->analizar();
    }

    public function analizar(): void
    {
        $this->mensaje = '';
        $this->errorMsg = '';
        $this->filas = [];
        $this->validate(messages: ['archivo.mimes' => 'Sube un archivo CSV o Excel (.xlsx).', 'archivo.max' => 'El archivo pesa más de 5 MB.']);

        try {
            $this->filas = app(ImportarClientesAction::class)->analizar($this->archivo->getRealPath(), $this->archivo->getClientOriginalName());
        } catch (\Throwable $e) {
            $this->errorMsg = MensajeError::paraUsuario($e, 'No se pudo leer el archivo.');
        }
    }

    public function getValidasProperty(): int
    {
        return count(array_filter($this->filas, fn ($f) => $f['errores'] === []));
    }

    public function importar(): void
    {
        $this->errorMsg = '';
        try {
            $creados = app(ImportarClientesAction::class)->importar($this->filas);
            $this->mensaje = $creados === 1 ? 'Se importó 1 cliente.' : "Se importaron {$creados} clientes.";
            $this->filas = array_values(array_filter($this->filas, fn ($f) => $f['errores'] !== []));
            $this->reset('archivo');
        } catch (\Throwable $e) {
            $this->errorMsg = MensajeError::paraUsuario($e, 'No se pudo importar.');
        }
    }

    public function descargarErrores()
    {
        $csv = app(ImportarClientesAction::class)->reporteErrores($this->filas);

        return response()->streamDownload(fn () => print($csv), 'errores-importacion-clientes.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function descargarPlantilla()
    {
        $csv = "\xEF\xBB\xBFnombre,telefono,email,direccion,notas\nAna Pérez,5512345678,ana@ejemplo.com,\"Calle 1, Centro\",Prefiere WhatsApp\n";

        return response()->streamDownload(fn () => print($csv), 'plantilla-clientes.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}; ?>

<div class="space-y-4">
    <x-panel.encabezado titulo="Importar clientes"
        subtitulo="Sube un CSV o Excel con las columnas nombre, telefono, email, direccion y notas. Revisa la vista previa e importa solo los renglones válidos.">
        <x-slot:acciones>
            <button type="button" wire:click="descargarPlantilla" class="fp-btn fp-btn-fantasma rounded-xl">Bajar plantilla</button>
        </x-slot:acciones>
    </x-panel.encabezado>

    @if ($mensaje)
        <x-panel.alerta tipo="exito">{{ $mensaje }}</x-panel.alerta>
    @endif
    @if ($errorMsg)
        <x-panel.alerta tipo="error">{{ $errorMsg }}</x-panel.alerta>
    @endif

    <div class="fp-tarjeta p-5 fp-formulario fp-aparecer">
        <label class="block text-sm font-medium text-slate-700" for="archivo-clientes">Archivo</label>
        <input id="archivo-clientes" type="file" wire:model="archivo" accept=".csv,.txt,.xlsx,.xls" class="mt-2 block text-sm">
        <div wire:loading wire:target="archivo" class="mt-2 text-xs text-slate-500">Leyendo el archivo…</div>
        @error('archivo') <p class="mt-1 text-xs text-fp-danger">{{ $message }}</p> @enderror
    </div>

    @if ($filas !== [])
        @php
            $conError = count($filas) - $this->validas;
        @endphp
        <div class="flex flex-wrap items-center gap-3 fp-aparecer" data-vista-previa>
            <span class="rounded-full bg-fp-badge-success-bg px-3 py-1 text-xs font-medium text-fp-badge-success-fg">{{ $this->validas }} válidos</span>
            <span class="rounded-full {{ $conError ? 'bg-fp-danger-soft text-fp-badge-danger-fg' : 'bg-slate-100 text-slate-500' }} px-3 py-1 text-xs font-medium">{{ $conError }} con error</span>
            <div class="ml-auto flex gap-2">
                @if ($conError)
                    <button type="button" wire:click="descargarErrores" class="fp-btn fp-btn-fantasma rounded-xl">Bajar reporte de errores</button>
                @endif
                @if ($this->validas)
                    <button type="button" wire:click="importar" wire:loading.attr="disabled"
                        wire:confirm="¿Importar {{ $this->validas }} clientes? Los renglones con error no se importan."
                        class="fp-btn fp-btn-primario rounded-xl">Importar {{ $this->validas }} válidos</button>
                @endif
            </div>
        </div>

        <div class="fp-tarjeta overflow-x-auto fp-aparecer">
            <table class="fp-tabla min-w-full text-sm">
                <thead class="bg-fp-page text-left text-[11px] uppercase tracking-wide text-slate-600">
                    <tr><th class="px-4 py-2">Fila</th><th class="px-4 py-2">Nombre</th><th class="px-4 py-2">Teléfono</th><th class="px-4 py-2">Correo</th><th class="px-4 py-2">Resultado</th></tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach (array_slice($filas, 0, 300) as $f)
                        <tr wire:key="fila-{{ $f['fila'] }}" class="transition-colors {{ $f['errores'] ? 'bg-fp-danger-soft/40' : 'hover:bg-slate-50' }}">
                            <td class="px-4 py-2 text-xs text-slate-500">{{ $f['fila'] }}</td>
                            <td class="px-4 py-2">{{ $f['nombre'] }}</td>
                            <td class="px-4 py-2">{{ $f['telefono'] }}</td>
                            <td class="px-4 py-2">{{ $f['email'] }}</td>
                            <td class="px-4 py-2 text-xs {{ $f['errores'] ? 'text-fp-danger' : 'text-emerald-700' }}">{{ $f['errores'] ? implode(' ', $f['errores']) : 'Listo para importar' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            @if (count($filas) > 300)
                <p class="p-3 text-xs text-slate-500">Se muestran 300 de {{ count($filas) }} renglones. El reporte de errores trae todos.</p>
            @endif
        </div>
    @endif
</div>
