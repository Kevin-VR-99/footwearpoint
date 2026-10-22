<?php

use App\Models\Distribuidora;
use App\Services\Distribuidora\ActualizarPerfilDistribuidoraAction;
use App\Services\Distribuidora\CambiarSubdominioAction;
use App\Services\Tienda\DominioTienda;
use App\Services\Tienda\EnlaceTienda;
use App\Support\WhatsApp;
use Illuminate\Validation\ValidationException;
use App\Support\Tenant;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Layout('layouts.panel')] #[Title('Perfil — FootwearPoint')] class extends Component {
    use WithFileUploads;

    public string $nombre_comercial = '';
    public ?string $descripcion_publica = null;
    public ?string $direccion_publica = null;
    public ?string $telefono_publico = null;

    /** llamadas: el teléfono solo se muestra para marcar. whatsapp: la tienda ofrece escribirles. */
    public string $uso_telefono = 'llamadas';

    public ?string $email_publico = null;
    public ?string $horario_publico = null;
    public $logotipo = null;
    public ?string $logotipo_url_actual = null;

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
        $this->uso_telefono = $distribuidora->telefono_es_whatsapp ? 'whatsapp' : 'llamadas';
        $this->email_publico = $distribuidora->email_publico;
        $this->horario_publico = $distribuidora->horario_publico;
        $this->logotipo_url_actual = $distribuidora->logotipo_url;
        $this->subdominio = (string) $distribuidora->subdominio;
        $this->urlTienda = app(EnlaceTienda::class)->url($distribuidora);
    }

    public function guardarPerfil(): void
    {
        $datos = $this->validate([
            'nombre_comercial' => ['required', 'string', 'max:150'],
            'descripcion_publica' => ['nullable', 'string', 'max:2000'],
            'direccion_publica' => ['nullable', 'string', 'max:300'],
            'telefono_publico' => ['nullable', 'string', 'max:30'],
            'uso_telefono' => ['required', 'in:llamadas,whatsapp'],
            'email_publico' => ['nullable', 'email', 'max:190'],
            'horario_publico' => ['nullable', 'string', 'max:300'],
            'logotipo' => ['nullable', 'image', 'mimes:jpg,jpeg,png', 'max:2048'],
        ], [
            'descripcion_publica.max' => 'La descripción pública no puede tener más de 2000 caracteres.',
        ]);

        $telefono = trim((string) ($datos['telefono_publico'] ?? ''));
        $quiereWhatsApp = $datos['uso_telefono'] === 'whatsapp' && $telefono !== '';

        if ($quiereWhatsApp && WhatsApp::normalizarTelefono($telefono) === null) {
            $this->addError('uso_telefono', 'Ese número no se puede usar en WhatsApp. Escríbelo con 10 dígitos o elige solo llamadas.');

            return;
        }

        $datos['telefono_publico'] = $telefono !== '' ? $telefono : null;
        $datos['telefono_es_whatsapp'] = $quiereWhatsApp;
        unset($datos['uso_telefono']);

        $distribuidora = Distribuidora::findOrFail(Tenant::id());
        $conLogotipo = $this->logotipo !== null;
        $guardada = app(ActualizarPerfilDistribuidoraAction::class)->ejecutar(
            $distribuidora,
            collect($datos)->except('logotipo')->all(),
            $this->logotipo
        );

        // Si se mandó logotipo y no quedó guardado, se dice en vez de "Listo".
        if ($conLogotipo && blank($guardada->logotipo_url)) {
            $this->addError('logotipo', 'No pudimos guardar el logotipo. Intenta subirlo de nuevo.');

            return;
        }

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

<div class="mx-auto max-w-5xl">
    <x-panel.encabezado eyebrow="Distribuidora" titulo="Perfil" nivel="h1"
        subtitulo="Datos generales de tu distribuidora." class="mb-6" />
    <form wire:submit="guardarPerfil" x-data="{ subiendo: false }"
        x-on:livewire-upload-start="subiendo = true"
        x-on:livewire-upload-finish="subiendo = false"
        x-on:livewire-upload-cancel="subiendo = false"
        x-on:livewire-upload-error="subiendo = false"
        class="fp-tarjeta p-5 sm:p-6 space-y-4 max-w-2xl fp-formulario fp-aparecer">
        <div class="fp-campo">
            <label for="perfil-nombre-comercial" class="block text-sm font-medium text-slate-700 mb-1">Nombre Comercial</label>
            <input id="perfil-nombre-comercial" type="text" wire:model="nombre_comercial" class="w-full rounded-lg bg-white text-sm shadow-sm focus:ring-fp-primary">
            @error('nombre_comercial') <span class="text-fp-badge-danger-fg text-xs">{{ $message }}</span> @enderror
        </div>
        <div class="fp-campo">
            <label for="perfil-descripcion-publica" class="block text-sm font-medium text-slate-700 mb-1">Descripción</label>
            <textarea id="perfil-descripcion-publica" wire:model="descripcion_publica" rows="3" class="w-full rounded-lg bg-white text-sm shadow-sm focus:ring-fp-primary"></textarea>
        </div>
        <div class="fp-campo">
            <label for="perfil-direccion-publica" class="block text-sm font-medium text-slate-700 mb-1">Dirección</label>
            <input id="perfil-direccion-publica" type="text" wire:model="direccion_publica" class="w-full rounded-lg bg-white text-sm shadow-sm focus:ring-fp-primary">
            <p class="mt-1 text-xs text-slate-500">Escribe calle, número, colonia y ciudad: con ella se muestra el mapa de tu tienda.</p>
        </div>
        <div class="grid grid-cols-2 gap-4">
            <div class="fp-campo">
                <label for="perfil-telefono-publico" class="block text-sm font-medium text-slate-700 mb-1">Teléfono</label>
                <input id="perfil-telefono-publico" type="text" wire:model.live="telefono_publico" placeholder="+52 55 1234 5678" class="w-full rounded-lg bg-white text-sm shadow-sm focus:ring-fp-primary">
            </div>
            <div class="fp-campo">
                <label for="perfil-email-publico" class="block text-sm font-medium text-slate-700 mb-1">Correo</label>
                <input id="perfil-email-publico" type="email" wire:model="email_publico" class="w-full rounded-lg bg-white text-sm shadow-sm focus:ring-fp-primary">
            </div>
        </div>
        @if (trim((string) $telefono_publico) !== '')
            <fieldset class="fp-campo" data-uso-telefono>
                <legend class="block text-sm font-medium text-slate-700 mb-2">¿Este número es solo para llamadas o también recibe mensajes de WhatsApp?</legend>
                <div class="flex flex-col gap-2 text-sm text-slate-700">
                    <label class="inline-flex items-center gap-2">
                        <input type="radio" name="uso_telefono" value="llamadas" wire:model="uso_telefono" class="text-fp-primary">
                        Solo llamadas
                    </label>
                    <label class="inline-flex items-center gap-2">
                        <input type="radio" name="uso_telefono" value="whatsapp" wire:model="uso_telefono" class="text-fp-primary">
                        También WhatsApp
                    </label>
                </div>
                <p class="mt-2 text-xs text-slate-500">El botón «Escríbenos» de tu tienda aparece cuando eliges WhatsApp y guardas. Si lo dejas en llamadas, el teléfono sigue visible para marcar.</p>
                @error('uso_telefono') <span class="text-fp-badge-danger-fg text-xs">{{ $message }}</span> @enderror
            </fieldset>
        @endif
        <div class="fp-campo">
            <label for="perfil-horario-publico" class="block text-sm font-medium text-slate-700 mb-1">Horario</label>
            <input id="perfil-horario-publico" type="text" wire:model="horario_publico" placeholder="Lunes a sábado, 9:00 a 19:00" class="w-full rounded-lg bg-white text-sm shadow-sm focus:ring-fp-primary">
        </div>
        <div class="fp-campo">
            <label class="block text-sm font-medium text-slate-700 mb-1">Logotipo</label>
            @if ($logotipo)
                <img src="{{ $logotipo->temporaryUrl() }}" alt="Vista previa del logotipo" loading="lazy" decoding="async" width="64" height="64" class="h-16 w-16 object-cover rounded mb-2">
            @elseif ($logotipo_url_actual)
                <img src="{{ $logotipo_url_actual }}" alt="Logotipo actual" loading="lazy" decoding="async" width="64" height="64" class="h-16 w-16 object-cover rounded mb-2">
            @endif
            <input type="file" wire:model="logotipo" aria-label="Logotipo" accept="image/png,image/jpeg">
            <p x-show="subiendo" x-cloak class="text-xs text-fp-text-muted mt-1">Subiendo logotipo…</p>
            @error('logotipo') <span class="block text-fp-badge-danger-fg text-xs">{{ $message }}</span> @enderror
            <p class="text-xs text-fp-text-muted mt-1">PNG o JPG, hasta 2MB.</p>
        </div>
        <button type="submit" class="fp-btn fp-btn-primario" wire:loading.attr="disabled" wire:target="logotipo,guardarPerfil" x-bind:disabled="subiendo">Guardar Cambios</button>
    </form>

    {{-- TG-232 (E3-02): subdominio de la tienda pública. --}}
    <form wire:submit="guardarSubdominio" class="mt-6 fp-tarjeta p-5 sm:p-6 space-y-3 max-w-2xl">
        <div class="fp-campo">
            <label class="block text-sm font-medium text-slate-700 mb-1">Subdominio de tu tienda</label>
            <div class="flex items-center gap-2">
                <input type="text" wire:model="subdominio" aria-label="Subdominio" maxlength="63" autocomplete="off"
                    class="w-full rounded-lg bg-white text-sm shadow-sm focus:ring-fp-primary">
                @if (DominioTienda::base())
                    <span class="shrink-0 text-sm text-fp-text-muted">.{{ DominioTienda::base() }}</span>
                @endif
            </div>
            @error('subdominio') <span class="text-fp-badge-danger-fg text-xs">{{ $message }}</span> @enderror
            <p class="text-xs text-fp-text-muted mt-1">De 3 a 63 caracteres: letras minúsculas, números y guiones.</p>
            @if ($urlTienda)
                <p class="text-xs text-fp-text-muted mt-1">Tu tienda: <a href="{{ $urlTienda }}" target="_blank" rel="noopener" class="fp-enlace">{{ $urlTienda }}</a></p>
            @endif
        </div>
        <button type="submit" class="fp-btn fp-btn-primario" wire:loading.attr="disabled">Guardar subdominio</button>
    </form>
</div>
