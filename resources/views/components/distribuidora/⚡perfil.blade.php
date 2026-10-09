<?php

use App\Models\Distribuidora;
use App\Services\Distribuidora\ActualizarPerfilDistribuidoraAction;
use App\Services\Distribuidora\CambiarSubdominioAction;
use App\Services\Tienda\DominioTienda;
use App\Services\Tienda\EnlaceTienda;
use Illuminate\Validation\ValidationException;
use App\Support\Tenant;
use Livewire\Component;
use Livewire\WithFileUploads;

new class extends Component {
    use WithFileUploads;

    public string $nombre_comercial = '';
    public ?string $descripcion_publica = null;
    public ?string $direccion_publica = null;
    public ?string $telefono_publico = null;
    public ?string $email_publico = null;
    public ?string $horario_publico = null;
    public $logotipo = null;
    public ?string $logotipo_url_actual = null;

    /** TG-197 (G16): sus categorías del directorio, solo para ver (las asigna FootwearPoint). */
    public array $categoriasDirectorio = [];

    /** TG-232 (E3-02): subdominio de su tienda y la dirección con la que se abre hoy. */
    public string $subdominio = '';
    public ?string $urlTienda = null;

    public function mount(): void
    {
        $distribuidora = Distribuidora::findOrFail(Tenant::id());
        $this->nombre_comercial = $distribuidora->nombre_comercial;
        $this->descripcion_publica = $distribuidora->descripcion_publica;
        $this->direccion_publica = $distribuidora->direccion_publica;
        $this->telefono_publico = $distribuidora->telefono_publico;
        $this->email_publico = $distribuidora->email_publico;
        $this->horario_publico = $distribuidora->horario_publico;
        $this->logotipo_url_actual = $distribuidora->logotipo_url;
        $this->subdominio = (string) $distribuidora->subdominio;
        $this->urlTienda = app(EnlaceTienda::class)->url($distribuidora);
        $this->categoriasDirectorio = $distribuidora->categoriasDirectorio()
            ->activas()
            ->orderBy('nombre')
            ->pluck('nombre')
            ->all();
    }

    public function guardarPerfil(): void
    {
        $datos = $this->validate([
            'nombre_comercial' => ['required', 'string', 'max:150'],
            'descripcion_publica' => ['nullable', 'string'],
            'direccion_publica' => ['nullable', 'string', 'max:300'],
            'telefono_publico' => ['nullable', 'string', 'max:30'],
            'email_publico' => ['nullable', 'email', 'max:190'],
            'horario_publico' => ['nullable', 'string', 'max:300'],
            'logotipo' => ['nullable', 'image', 'mimes:jpg,jpeg,png', 'max:2048'],
        ]);

        $distribuidora = Distribuidora::findOrFail(Tenant::id());
        app(ActualizarPerfilDistribuidoraAction::class)->ejecutar(
            $distribuidora,
            collect($datos)->except('logotipo')->all(),
            $this->logotipo
        );

        $this->logotipo = null;
        $this->mount();
        $this->dispatch('guardado', mensaje: 'Perfil actualizado correctamente.');
    }

    /** TG-232 (E3-02): solo el administrador de la distribuidora. */
    public function guardarSubdominio(): void
    {
        abort_unless(auth()->user()?->hasRole('admin_distribuidora'), 403);

        $distribuidora = Distribuidora::findOrFail(Tenant::id());

        try {
            app(CambiarSubdominioAction::class)->ejecutar($distribuidora, $this->subdominio);
        } catch (ValidationException $e) {
            $this->addError('subdominio', collect($e->errors())->flatten()->first());

            return;
        }

        $this->mount();
        $this->dispatch('guardado', mensaje: 'Subdominio actualizado correctamente.');
    }

};
?>

<div>
    <form wire:submit="guardarPerfil" class="rounded-xl border border-slate-200/80 bg-white p-5 sm:p-6 space-y-4 max-w-2xl">
        <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">Nombre Comercial</label>
            <input type="text" wire:model="nombre_comercial" class="w-full rounded-lg border-slate-200 bg-white text-sm shadow-sm focus:border-fp-primary focus:ring-fp-primary">
            @error('nombre_comercial') <span class="text-fp-badge-danger-fg text-xs">{{ $message }}</span> @enderror
        </div>
        <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">Descripción</label>
            <textarea wire:model="descripcion_publica" rows="3" class="w-full rounded-lg border-slate-200 bg-white text-sm shadow-sm focus:border-fp-primary focus:ring-fp-primary"></textarea>
        </div>
        <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">Dirección</label>
            <input type="text" wire:model="direccion_publica" class="w-full rounded-lg border-slate-200 bg-white text-sm shadow-sm focus:border-fp-primary focus:ring-fp-primary">
        </div>
        <div class="grid grid-cols-2 gap-4">
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Teléfono</label>
                <input type="text" wire:model="telefono_publico" placeholder="+52 55 1234 5678" class="w-full rounded-lg border-slate-200 bg-white text-sm shadow-sm focus:border-fp-primary focus:ring-fp-primary">
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Correo</label>
                <input type="email" wire:model="email_publico" class="w-full rounded-lg border-slate-200 bg-white text-sm shadow-sm focus:border-fp-primary focus:ring-fp-primary">
            </div>
        </div>
        <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">Horario</label>
            <input type="text" wire:model="horario_publico" placeholder="Lunes a sábado, 9:00 a 19:00" class="w-full rounded-lg border-slate-200 bg-white text-sm shadow-sm focus:border-fp-primary focus:ring-fp-primary">
        </div>
        <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">Logotipo</label>
            @if ($logotipo)
                <img src="{{ $logotipo->temporaryUrl() }}" class="h-16 w-16 object-cover rounded mb-2">
            @elseif ($logotipo_url_actual)
                <img src="{{ $logotipo_url_actual }}" class="h-16 w-16 object-cover rounded mb-2">
            @endif
            <input type="file" wire:model="logotipo" accept="image/png,image/jpeg">
            <p class="text-xs text-fp-text-muted mt-1">PNG o JPG, hasta 2MB.</p>
        </div>
        <div>
            <span class="block text-sm font-medium text-slate-700 mb-1">Categorías del directorio</span>
            @if ($categoriasDirectorio === [])
                <p class="text-sm text-fp-text-muted">Todavía no tienes categorías en el directorio.</p>
            @else
                <div class="flex flex-wrap gap-1.5">
                    @foreach ($categoriasDirectorio as $nombreCategoria)
                        <span class="text-xs px-2 py-0.5 rounded-full bg-slate-100 text-slate-700">{{ $nombreCategoria }}</span>
                    @endforeach
                </div>
            @endif
            <p class="text-xs text-fp-text-muted mt-1">Las asigna FootwearPoint. Si te falta alguna, comunícate con nosotros.</p>
        </div>
        <button type="submit" class="rounded-lg bg-fp-primary px-4 py-2 text-sm font-medium text-white shadow-sm transition hover:bg-fp-primary/90" wire:loading.attr="disabled">Guardar Cambios</button>
    </form>

    {{-- TG-232 (E3-02): subdominio de la tienda pública. --}}
    <form wire:submit="guardarSubdominio" class="mt-6 rounded-xl border border-slate-200/80 bg-white p-5 sm:p-6 space-y-3 max-w-2xl">
        <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">Subdominio de tu tienda</label>
            <div class="flex items-center gap-2">
                <input type="text" wire:model="subdominio" maxlength="63" autocomplete="off"
                    class="w-full rounded-lg border-slate-200 bg-white text-sm shadow-sm focus:border-fp-primary focus:ring-fp-primary">
                @if (DominioTienda::base())
                    <span class="shrink-0 text-sm text-fp-text-muted">.{{ DominioTienda::base() }}</span>
                @endif
            </div>
            @error('subdominio') <span class="text-fp-badge-danger-fg text-xs">{{ $message }}</span> @enderror
            <p class="text-xs text-fp-text-muted mt-1">De 3 a 63 caracteres: letras minúsculas, números y guiones.</p>
            @if ($urlTienda)
                <p class="text-xs text-fp-text-muted mt-1">Tu tienda: <a href="{{ $urlTienda }}" target="_blank" rel="noopener" class="text-fp-primary hover:underline">{{ $urlTienda }}</a></p>
            @endif
        </div>
        <button type="submit" class="rounded-lg bg-fp-primary px-4 py-2 text-sm font-medium text-white shadow-sm transition hover:bg-fp-primary/90" wire:loading.attr="disabled">Guardar subdominio</button>
    </form>
</div>
