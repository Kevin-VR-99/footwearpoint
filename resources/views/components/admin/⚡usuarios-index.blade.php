<?php

use App\Models\Distribuidora;
use App\Services\Personal\CrearPersonalAction;
use App\Support\PersonalPlataforma;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

/*
| TG-335 (K37) - Personal de la plataforma. TG-336 (K38) - Alta.
|
| Cambiar correo, rol o contraseña es de K39, y las bajas de K40. Los filtros
| van en la dirección para que «atrás» desde la ficha regrese a la misma
| búsqueda.
*/
new #[Layout('layouts.admin')] #[Title('Usuarios — Admin')] class extends Component
{
    use WithFileUploads;
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $buscar = '';

    #[Url(as: 'rol', except: '')]
    public string $filtro_rol = '';

    #[Url(as: 'estado', except: '')]
    public string $filtro_estado = '';

    #[Url(as: 'distribuidora', except: '')]
    public string $filtro_distribuidora = '';

    public bool $mostrandoAlta = false;

    public string $nombre = '';

    public string $email = '';

    public string $telefono = '';

    public string $direccion = '';

    public string $rol = 'empleado';

    public string $distribuidora_id = '';

    public string $acceso = CrearPersonalAction::ACCESO_TEMPORAL;

    public bool $confirmar_admin_general = false;

    public $foto = null;

    public string $avisoAlta = '';

    /**
     * Protegida a propósito: Livewire no la guarda en el estado del componente
     * ni en la sesión, así que solo existe en la respuesta del alta.
     *
     * @var array{email: string, password: string}|null
     */
    protected ?array $passwordTemporal = null;

    /** En cada petición, y sin cambiar el equipo de permisos. */
    public function boot(): void
    {
        abort_unless(Auth::check() && PersonalPlataforma::esAdminGeneral(Auth::user()), 403, 'Solo admin general.');
    }

    public function updated(string $propiedad): void
    {
        if (str_starts_with($propiedad, 'filtro_') || $propiedad === 'buscar') {
            $this->resetPage();
        }

        if ($propiedad === 'rol') {
            $this->confirmar_admin_general = false;
            $this->resetValidation(['rol', 'distribuidora_id', 'direccion', 'confirmar_admin_general']);

            if ($this->rol === PersonalPlataforma::ADMIN_GENERAL) {
                $this->distribuidora_id = '';
                $this->direccion = '';
            }
        }
    }

    public function limpiarFiltros(): void
    {
        $this->reset('buscar', 'filtro_rol', 'filtro_estado', 'filtro_distribuidora');
        $this->resetPage();
    }

    public function abrirAlta(): void
    {
        $this->limpiarAlta();
        $this->avisoAlta = '';
        $this->mostrandoAlta = true;
    }

    public function cancelarAlta(): void
    {
        $this->limpiarAlta();
        $this->mostrandoAlta = false;
    }

    public function quitarFoto(): void
    {
        $this->foto = null;
        $this->resetValidation('foto');
    }

    public function guardarAlta(CrearPersonalAction $accion): void
    {
        $this->avisoAlta = '';

        $resultado = $accion->ejecutar(
            $this->only(['nombre', 'email', 'telefono', 'direccion', 'rol', 'distribuidora_id', 'acceso', 'confirmar_admin_general']),
            $this->foto instanceof UploadedFile ? $this->foto : null,
        );

        $this->cancelarAlta();
        unset($this->personal, $this->roles);

        if ($resultado['password_temporal'] !== null) {
            $this->passwordTemporal = [
                'email' => $resultado['usuario']->email,
                'password' => $resultado['password_temporal'],
            ];

            return;
        }

        if ($resultado['error_enlace'] !== null) {
            $this->avisoAlta = 'La cuenta de '.$resultado['usuario']->email.' se creó, pero no se envió el enlace: '
                .$resultado['error_enlace'].' Puede pedir otro desde «¿Olvidaste tu contraseña?».';

            return;
        }

        $this->dispatch('guardado', mensaje: $resultado['aviso_enlace']);
    }

    /** Al volver a dibujar, la contraseña temporal ya no está. */
    public function cerrarPasswordTemporal(): void
    {
    }

    public function passwordTemporalVisible(): ?array
    {
        return $this->passwordTemporal;
    }

    private function limpiarAlta(): void
    {
        $this->reset('nombre', 'email', 'telefono', 'direccion', 'rol', 'distribuidora_id', 'acceso', 'confirmar_admin_general', 'foto');
        $this->resetValidation();
    }

    #[Computed]
    public function personal()
    {
        return PersonalPlataforma::consulta(
            $this->buscar,
            $this->filtro_rol,
            $this->filtro_estado,
            $this->filtro_distribuidora
        )->paginate(20);
    }

    #[Computed]
    public function roles(): array
    {
        return PersonalPlataforma::rolesDe($this->personal->pluck('id')->map(fn ($id) => (int) $id)->all());
    }

    #[Computed]
    public function distribuidoras()
    {
        return Distribuidora::query()
            ->orderBy('nombre_comercial')
            ->get(['id', 'nombre_comercial', 'razon_social']);
    }

    #[Computed]
    public function distribuidorasActivas()
    {
        return PersonalPlataforma::distribuidorasActivas();
    }
};
?>

<div>
    <x-panel.encabezado eyebrow="Personal" titulo="Usuarios"
        subtitulo="Admins y empleados de toda la plataforma. Los clientes no aparecen aquí." class="mb-6">
        <x-slot:acciones>
            <x-panel.boton wire:click="abrirAlta" data-nuevo-usuario>+ Nuevo usuario</x-panel.boton>
        </x-slot:acciones>
    </x-panel.encabezado>

    @if (session('aviso_personal'))
        <x-panel.alerta tipo="exito" class="mb-4" data-aviso-personal>{{ session('aviso_personal') }}</x-panel.alerta>
    @endif

    @if ($avisoAlta !== '')
        <x-panel.alerta tipo="aviso" class="mb-4" data-aviso-alta>{{ $avisoAlta }}</x-panel.alerta>
    @endif

    <x-personas.tarjeta titulo="{{ $this->personal->total() }} {{ $this->personal->total() === 1 ? 'persona' : 'personas' }}" data-lista="personal">
        <x-ui.cargando objetivo="buscar,filtro_rol,filtro_estado,filtro_distribuidora,limpiarFiltros,gotoPage,nextPage,previousPage" />

        <x-slot:filtros>
            <x-ui.buscador class="min-w-[14rem] flex-1" wire:model.live.debounce.300ms="buscar"
                placeholder="Buscar por nombre, correo o teléfono…" etiqueta="Buscar personal" />
            <x-ui.selector ancho="auto" wire:model.live="filtro_rol" vacio="Todos los roles" aria-label="Filtrar por rol">
                @foreach (\App\Support\PersonalPlataforma::ROLES as $valor => $etiqueta)
                    <option value="{{ $valor }}">{{ $etiqueta }}</option>
                @endforeach
            </x-ui.selector>
            <x-ui.selector ancho="auto" wire:model.live="filtro_estado" vacio="Todos los estados" aria-label="Filtrar por estado">
                @foreach (\App\Support\PersonalPlataforma::ESTADOS as $valor => $etiqueta)
                    <option value="{{ $valor }}">{{ $etiqueta }}</option>
                @endforeach
            </x-ui.selector>
            <x-ui.selector ancho="auto" wire:model.live="filtro_distribuidora" vacio="Todas las distribuidoras" aria-label="Filtrar por distribuidora">
                @foreach ($this->distribuidoras as $distribuidora)
                    <option value="{{ $distribuidora->id }}">{{ $distribuidora->nombre_comercial ?: $distribuidora->razon_social }}</option>
                @endforeach
            </x-ui.selector>
            <x-panel.boton variante="secundario" wire:click="limpiarFiltros">Limpiar</x-panel.boton>
        </x-slot:filtros>

        <table class="fp-tabla-personas">
            <thead>
                <tr>
                    <th scope="col">Foto</th>
                    <th scope="col">Nombre</th>
                    <th scope="col">Correo</th>
                    <th scope="col">Roles</th>
                    <th scope="col">Estado</th>
                    <th scope="col">Alta</th>
                    <th scope="col" class="text-right">Acciones</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($this->personal as $persona)
                    @php($nombrePersona = $persona->nombre ?: 'Sin nombre')
                    <tr class="fp-fila-persona" wire:key="personal-{{ $persona->id }}" data-persona="{{ $persona->id }}">
                        <td class="fp-celda-avatar w-11 pr-0"><x-ui.avatar :url="$persona->foto_url" :nombre="$nombrePersona" /></td>
                        <td class="fp-celda-nombre">{{ $nombrePersona }}</td>
                        <td class="fp-celda-correo" data-etiqueta="Correo">{{ $persona->email }}</td>
                        <td><x-personas.roles :roles="$this->roles[$persona->id] ?? []" /></td>
                        <td><x-personas.estado :estado="$persona->estado" /></td>
                        <td class="whitespace-nowrap tabular-nums" data-etiqueta="Alta">{{ $persona->created_at?->format('d/m/Y') ?? '—' }}</td>
                        <td class="fp-celda-acciones">
                            <div class="fp-acciones-persona">
                                <x-personas.accion etiqueta="Ver ficha" :persona="$nombrePersona" :href="route('admin.usuarios.ficha', $persona->id)" />
                                <x-personas.accion icono="lapiz" etiqueta="Editar" :persona="$nombrePersona"
                                    :href="route('admin.usuarios.ficha', ['usuario' => $persona->id, 'editar' => 1])" />
                            </div>
                        </td>
                    </tr>
                @empty
                    <x-panel.vacio colspan="7" mensaje="No hay personal con estos filtros." />
                @endforelse
            </tbody>
        </table>

        @if ($this->personal->hasPages())
            <div class="mt-4 border-t border-slate-100 pt-3">
                {{ $this->personal->links() }}
            </div>
        @endif
    </x-personas.tarjeta>

    @if ($mostrandoAlta)
        @php($esAdminGeneral = $rol === \App\Support\PersonalPlataforma::ADMIN_GENERAL)
        <x-panel.modal titulo="Nuevo usuario" subtitulo="Cuenta de personal para entrar al panel."
            cerrar="cancelarAlta" formulario="guardarAlta" ancho="max-w-2xl" class="fp-formulario" data-modal="alta-personal">
            <div class="space-y-4">
                <x-personas.foto modelo="foto" :archivo="$foto" :nombre="$nombre" quitar="quitarFoto" id="personal-foto" datos="personal" />

                <div class="grid gap-4 sm:grid-cols-2">
                    <x-ui.campo etiqueta="Nombre" para="personal-nombre" error="nombre" requerido>
                        <x-ui.entrada id="personal-nombre" wire:model="nombre" autofocus required />
                    </x-ui.campo>
                    <x-ui.campo etiqueta="Correo" para="personal-email" error="email" requerido>
                        <x-ui.entrada tipo="email" id="personal-email" wire:model="email" required />
                    </x-ui.campo>
                    <x-ui.campo etiqueta="Teléfono" para="personal-telefono" error="telefono">
                        <x-ui.entrada tipo="tel" id="personal-telefono" wire:model="telefono" />
                    </x-ui.campo>
                    <x-ui.campo etiqueta="Rol" para="personal-rol" error="rol" requerido>
                        <x-ui.selector id="personal-rol" wire:model.live="rol">
                            @foreach (\App\Support\PersonalPlataforma::ROLES as $valor => $etiqueta)
                                <option value="{{ $valor }}">{{ $etiqueta }}</option>
                            @endforeach
                        </x-ui.selector>
                    </x-ui.campo>

                    @unless ($esAdminGeneral)
                        <x-ui.campo etiqueta="Distribuidora" para="personal-distribuidora" error="distribuidora_id" requerido>
                            <x-ui.selector id="personal-distribuidora" wire:model="distribuidora_id" vacio="Elige una distribuidora" required>
                                @foreach ($this->distribuidorasActivas as $distribuidora)
                                    <option value="{{ $distribuidora->id }}">{{ $distribuidora->nombre_comercial ?: $distribuidora->razon_social }}</option>
                                @endforeach
                            </x-ui.selector>
                        </x-ui.campo>
                        <x-ui.campo etiqueta="Dirección" para="personal-direccion" error="direccion">
                            <x-ui.entrada id="personal-direccion" wire:model="direccion" maxlength="300" />
                        </x-ui.campo>
                    @endunless
                </div>

                <fieldset class="space-y-2">
                    <legend class="fp-campo-etiqueta">¿Cómo va a entrar?</legend>
                    <label class="flex items-start gap-2 text-sm text-slate-700">
                        <input type="radio" wire:model="acceso" value="temporal" class="mt-0.5">
                        <span>Contraseña temporal. La verás una sola vez y deberá cambiarla al entrar.</span>
                    </label>
                    <label class="flex items-start gap-2 text-sm text-slate-700">
                        <input type="radio" wire:model="acceso" value="enlace" class="mt-0.5">
                        <span>Enlace por correo para que defina su contraseña. Vence en 60 minutos.</span>
                    </label>
                    @error('acceso') <p class="fp-campo-error" role="alert">{{ $message }}</p> @enderror
                </fieldset>

                @if ($esAdminGeneral)
                    <x-panel.alerta tipo="peligro" data-confirmar-admin-general>
                        <p class="font-semibold">Vas a crear otro admin general.</p>
                        <p class="mt-1">Tendrá acceso total a FootwearPoint: todas las distribuidoras, planes, catálogo, usuarios y auditoría.</p>
                        <label class="mt-3 flex items-start gap-2 font-medium">
                            <input type="checkbox" wire:model="confirmar_admin_general" class="mt-0.5">
                            <span>Confirmo que esta persona debe tener acceso total.</span>
                        </label>
                    </x-panel.alerta>
                    @error('confirmar_admin_general') <p class="fp-campo-error" role="alert">{{ $message }}</p> @enderror
                @endif
            </div>

            <x-slot:pie>
                <button type="button" wire:click="cancelarAlta" class="fp-btn fp-btn-secundario">Cancelar</button>
                <button type="submit" class="fp-btn fp-btn-primario" wire:loading.attr="disabled" wire:target="guardarAlta,foto">
                    <span wire:loading.remove wire:target="guardarAlta">Crear usuario</span>
                    <span wire:loading wire:target="guardarAlta">Creando…</span>
                </button>
            </x-slot:pie>
        </x-panel.modal>
    @endif

    @if ($temporal = $this->passwordTemporalVisible())
        <x-panel.modal titulo="Contraseña temporal" subtitulo="Cópiala ahora: no se volverá a mostrar." data-modal="password-temporal">
            <p class="text-sm text-slate-600">Para <span class="font-medium text-slate-800">{{ $temporal['email'] }}</span>. Deberá cambiarla la primera vez que entre.</p>
            <code class="mt-3 block select-all break-all rounded-lg bg-fp-page px-4 py-3 text-center text-lg font-semibold tracking-wide text-fp-sidebar" data-password-temporal>{{ $temporal['password'] }}</code>
            <x-slot:pie>
                <button type="button" wire:click="cerrarPasswordTemporal" class="fp-btn fp-btn-primario">Ya la guardé</button>
            </x-slot:pie>
        </x-panel.modal>
    @endif
</div>
