<?php

use App\Services\Perfil\CambioContactoAction;
use App\Services\Personal\ActualizarPersonalAction;
use App\Services\Personal\CambiarCorreoPersonalAction;
use App\Services\Personal\CambiarRolPersonalAction;
use App\Services\Personal\RestablecerAccesoPersonalAction;
use App\Support\PersonalPlataforma;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

/*
| TG-335 (K37) - Ficha de una persona del personal. TG-336 (K38) - Edición de
| nombre, teléfono, dirección y foto. TG-337 (K39) - Correo, rol y contraseña,
| con la contraseña del admin general.
|
| Un cliente no tiene ficha aquí: su id responde 404.
*/
new #[Layout('layouts.admin')] #[Title('Ficha de usuario — Admin')] class extends Component
{
    use WithFileUploads;
    use WithPagination;

    #[Locked]
    public int $usuarioId;

    public bool $editando = false;

    public string $nombre = '';

    public string $telefono = '';

    public string $direccion = '';

    public $foto = null;

    /** El cambio abierto: correo, rol o password. */
    public string $cambio = '';

    public string $correo_nuevo = '';

    public string $asignacion = '';

    public string $rol_nuevo = '';

    public string $distribuidora_nueva = '';

    public bool $confirmar_consecuencias = false;

    public string $modo_password = RestablecerAccesoPersonalAction::TEMPORAL;

    public string $password_confirmacion = '';

    /** Protegida: solo existe en la respuesta que la genera. */
    protected ?string $passwordTemporal = null;

    /** En cada petición, y sin cambiar el equipo de permisos. */
    public function boot(): void
    {
        abort_unless(Auth::check() && PersonalPlataforma::esAdminGeneral(Auth::user()), 403, 'Solo admin general.');
    }

    public function mount(int $usuario): void
    {
        $this->usuarioId = $usuario;
        abort_if($this->persona === null, 404);

        if (request()->boolean('editar')) {
            $this->abrirEdicion();
        }
    }

    public function abrirEdicion(): void
    {
        $persona = $this->personaObligatoria();

        $this->nombre = (string) $persona->nombre;
        $this->telefono = (string) $persona->telefono;
        $this->direccion = (string) $persona->direccion;
        $this->foto = null;
        $this->resetValidation();
        $this->editando = true;
    }

    public function cancelarEdicion(): void
    {
        $this->editando = false;
        $this->foto = null;
        $this->resetValidation();
    }

    public function quitarFoto(): void
    {
        $this->foto = null;
        $this->resetValidation('foto');
    }

    public function guardarEdicion(ActualizarPersonalAction $accion): void
    {
        $accion->ejecutar(
            $this->personaObligatoria(),
            $this->only(['nombre', 'telefono', 'direccion']),
            $this->foto instanceof UploadedFile ? $this->foto : null,
        );

        $this->cancelarEdicion();
        unset($this->persona, $this->movimientos);
        $this->dispatch('guardado', mensaje: 'Datos actualizados.');
    }

    public function abrirCambio(string $tipo): void
    {
        abort_unless(in_array($tipo, ['correo', 'rol', 'password'], true), 404);

        $this->limpiarCambio();
        $this->asignacion = isset($this->roles[0]) ? $this->roles[0]['rol'].'|'.$this->roles[0]['equipo'] : '';
        $this->rol_nuevo = (string) array_key_first($this->opcionesRol());
        $this->cambio = $tipo;
    }

    public function cerrarCambio(): void
    {
        $this->limpiarCambio();
    }

    public function updated(string $propiedad): void
    {
        if ($propiedad === 'asignacion') {
            $this->rol_nuevo = (string) array_key_first($this->opcionesRol());
            $this->distribuidora_nueva = '';
        }

        if (in_array($propiedad, ['asignacion', 'rol_nuevo'], true)) {
            $this->confirmar_consecuencias = false;
            $this->resetValidation(['rol_nuevo', 'distribuidora_nueva', 'confirmar_consecuencias']);
        }
    }

    public function guardarCambio(): void
    {
        $admin = Auth::user();
        $persona = $this->personaObligatoria();

        try {
            $mensaje = match ($this->cambio) {
                'correo' => app(CambiarCorreoPersonalAction::class)
                    ->ejecutar($admin, $persona, $this->correo_nuevo, $this->password_confirmacion),
                'rol' => app(CambiarRolPersonalAction::class)
                    ->ejecutar($admin, $persona, $this->only(['asignacion', 'rol_nuevo', 'distribuidora_nueva', 'confirmar_consecuencias', 'password_confirmacion'])),
                'password' => $this->restablecerAcceso($admin, $persona),
                default => abort(404),
            };
        } finally {
            $this->password_confirmacion = '';
        }

        $this->limpiarCambio();
        unset($this->persona, $this->roles, $this->conDireccion, $this->movimientos, $this->correoPendiente);
        $this->dispatch('guardado', mensaje: $mensaje);
    }

    private function restablecerAcceso($admin, $persona): string
    {
        $resultado = app(RestablecerAccesoPersonalAction::class)
            ->ejecutar($admin, $persona, $this->modo_password, $this->password_confirmacion);

        $this->passwordTemporal = $resultado['password_temporal'];

        return $resultado['mensaje'];
    }

    private function limpiarCambio(): void
    {
        $this->reset('cambio', 'correo_nuevo', 'asignacion', 'rol_nuevo', 'distribuidora_nueva', 'confirmar_consecuencias', 'modo_password', 'password_confirmacion');
        $this->resetValidation();
    }

    /** Al volver a dibujar, la contraseña temporal ya no está. */
    public function cerrarPasswordTemporal(): void
    {
    }

    public function passwordTemporalVisible(): ?string
    {
        return $this->passwordTemporal;
    }

    public function rolOrigen(): string
    {
        return explode('|', $this->asignacion)[0];
    }

    /** @return array<string, string> Los roles a los que puede pasar esa asignación. */
    public function opcionesRol(): array
    {
        $opciones = array_diff_key(PersonalPlataforma::ROLES, [$this->rolOrigen() => true]);

        // El cambio más delicado nunca queda elegido de entrada.
        return array_diff_key($opciones, [PersonalPlataforma::ADMIN_GENERAL => true])
            + array_intersect_key($opciones, [PersonalPlataforma::ADMIN_GENERAL => true]);
    }

    public function esConversion(): bool
    {
        return $this->rol_nuevo !== '' && in_array(PersonalPlataforma::ADMIN_GENERAL, [$this->rolOrigen(), $this->rol_nuevo], true);
    }

    private function personaObligatoria()
    {
        return $this->persona ?? abort(404);
    }

    #[Computed]
    public function persona()
    {
        return PersonalPlataforma::consulta()->whereKey($this->usuarioId)->first();
    }

    #[Computed]
    public function roles(): array
    {
        return PersonalPlataforma::rolesDe([$this->usuarioId])[$this->usuarioId] ?? [];
    }

    /** El aviso de privacidad solo declara la dirección del personal de distribuidoras. */
    #[Computed]
    public function conDireccion(): bool
    {
        return PersonalPlataforma::tieneRolDeDistribuidora($this->usuarioId);
    }

    #[Computed]
    public function correoPendiente(): ?string
    {
        return app(CambioContactoAction::class)->pendientesDe($this->personaObligatoria())['email'] ?? null;
    }

    #[Computed]
    public function distribuidorasActivas()
    {
        return PersonalPlataforma::distribuidorasActivas();
    }

    #[Computed]
    public function ultimoInicio()
    {
        return PersonalPlataforma::ultimoInicio($this->usuarioId);
    }

    #[Computed]
    public function movimientos()
    {
        return PersonalPlataforma::movimientos($this->usuarioId)->paginate(15);
    }
};
?>

<div class="space-y-6">
    @php($persona = $this->persona)
    @php($nombrePersona = $persona->nombre ?: 'Sin nombre')

    <x-panel.encabezado :volver="route('admin.usuarios')" volver-texto="← Usuarios" :titulo="$nombrePersona"
        subtitulo="Datos, roles y movimientos.">
        <x-slot:acciones>
            <x-panel.boton variante="secundario" wire:click="abrirEdicion" data-editar-personal>Editar</x-panel.boton>
            <x-panel.boton variante="secundario" wire:click="abrirCambio('correo')" data-cambiar="correo">Cambiar correo</x-panel.boton>
            <x-panel.boton variante="secundario" wire:click="abrirCambio('rol')" data-cambiar="rol">Cambiar rol</x-panel.boton>
            <x-panel.boton variante="secundario" wire:click="abrirCambio('password')" data-cambiar="password">Restablecer contraseña</x-panel.boton>
        </x-slot:acciones>
    </x-panel.encabezado>

    <div class="grid gap-6 lg:grid-cols-2">
        <x-panel.seccion titulo="Datos">
            <div class="flex flex-col gap-4 p-4 sm:flex-row sm:p-5">
                <x-ui.avatar :url="$persona->foto_url" :nombre="$nombrePersona" tamano="h-20 w-20 text-xl" />
                <dl class="grid min-w-0 flex-1 grid-cols-1 gap-3 text-sm sm:grid-cols-2">
                    <div class="min-w-0">
                        <dt class="text-xs text-fp-text-muted">Correo</dt>
                        <dd class="break-words text-slate-800">{{ $persona->email }}</dd>
                        <x-ui.estado-correo :usuario="$persona" />
                        @if ($this->correoPendiente)
                            <p class="mt-1 break-words text-xs text-fp-text-muted" data-correo-pendiente>Por confirmar: {{ $this->correoPendiente }}</p>
                        @endif
                    </div>
                    <div>
                        <dt class="text-xs text-fp-text-muted">Teléfono</dt>
                        <dd class="text-slate-800">{{ $persona->telefono ?: '—' }}</dd>
                    </div>
                    @if ($this->conDireccion)
                        <div class="min-w-0 sm:col-span-2" data-direccion>
                            <dt class="text-xs text-fp-text-muted">Dirección</dt>
                            <dd class="break-words text-slate-800">{{ $persona->direccion ?: '—' }}</dd>
                        </div>
                    @endif
                    <div>
                        <dt class="text-xs text-fp-text-muted">Estado</dt>
                        <dd class="mt-0.5"><x-personas.estado :estado="$persona->estado" /></dd>
                    </div>
                    <div>
                        <dt class="text-xs text-fp-text-muted">Alta</dt>
                        <dd class="tabular-nums text-slate-800">{{ $persona->created_at?->format('d/m/Y') ?? '—' }}</dd>
                    </div>
                    <div class="sm:col-span-2" data-ultimo-inicio>
                        <dt class="text-xs text-fp-text-muted">Último inicio de sesión</dt>
                        @if ($this->ultimoInicio)
                            <dd class="text-slate-800">
                                <span class="tabular-nums">{{ $this->ultimoInicio->created_at?->format('d/m/Y H:i') }}</span>
                                <span class="text-xs text-slate-500">· {{ \App\Support\Auditoria\EtiquetasAuditoria::etiqueta($this->ultimoInicio->accion) }}</span>
                            </dd>
                        @else
                            <dd class="text-slate-500">Sin registro</dd>
                        @endif
                    </div>
                </dl>
            </div>
        </x-panel.seccion>

        <x-panel.seccion titulo="Roles por distribuidora">
            <div class="p-4 sm:p-5">
                <x-personas.roles :roles="$this->roles" class="space-y-2" />
            </div>
        </x-panel.seccion>
    </div>

    <x-panel.seccion titulo="Movimientos auditados" nota="Lo que hizo esta persona, del más reciente al más antiguo." data-movimientos>
        <div class="relative">
            <x-ui.cargando objetivo="gotoPage,nextPage,previousPage" />
            <div class="overflow-x-auto">
                <table class="fp-tabla min-w-full text-sm">
                    <thead class="bg-fp-page text-left text-[11px] uppercase tracking-wide text-slate-600">
                        <tr>
                            <th scope="col" class="px-4 py-3 font-medium">Fecha</th>
                            <th scope="col" class="px-4 py-3 font-medium">Acción</th>
                            <th scope="col" class="px-4 py-3 font-medium">Entidad</th>
                            <th scope="col" class="px-4 py-3 font-medium">Distribuidora</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse ($this->movimientos as $movimiento)
                            <tr class="hover:bg-slate-50/80" wire:key="movimiento-{{ $movimiento->id }}">
                                <td class="whitespace-nowrap px-4 py-3 tabular-nums text-slate-700">{{ $movimiento->created_at?->format('d/m/Y H:i:s') }}</td>
                                <td class="px-4 py-3">
                                    <div class="text-[11px] uppercase tracking-wide text-fp-text-muted">{{ \App\Support\Auditoria\EtiquetasAuditoria::area($movimiento->accion) }}</div>
                                    <x-panel.insignia variante="info">{{ \App\Support\Auditoria\EtiquetasAuditoria::etiqueta($movimiento->accion) }}</x-panel.insignia>
                                </td>
                                <td class="px-4 py-3 text-slate-700">
                                    {{ $movimiento->entidad_tipo }}
                                    @if ($movimiento->entidad_id)
                                        <span class="text-slate-500">#{{ $movimiento->entidad_id }}</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-slate-700">{{ $movimiento->distribuidora?->nombre_comercial ?: ($movimiento->distribuidora?->razon_social ?: '—') }}</td>
                            </tr>
                        @empty
                            <x-panel.vacio colspan="4" mensaje="Esta persona no tiene movimientos auditados." icono="M9 12l2 2 4-4M12 3l7 3v6c0 4.5-3 8-7 9-4-1-7-4.5-7-9V6l7-3z" />
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if ($this->movimientos->hasPages())
                <div class="border-t border-slate-100 px-4 py-3">
                    {{ $this->movimientos->links() }}
                </div>
            @endif
        </div>
    </x-panel.seccion>

    @if ($editando)
        <x-panel.modal titulo="Editar usuario" subtitulo="El correo, el rol y la contraseña no se cambian aquí."
            cerrar="cancelarEdicion" formulario="guardarEdicion" ancho="max-w-xl" class="fp-formulario" data-modal="editar-personal">
            <div class="space-y-4">
                <x-personas.foto modelo="foto" :archivo="$foto" :actual="$persona->foto_url" :nombre="$nombre" quitar="quitarFoto" id="ficha-foto" datos="ficha" />

                <div class="grid gap-4 sm:grid-cols-2">
                    <x-ui.campo etiqueta="Nombre" para="ficha-nombre" error="nombre" requerido>
                        <x-ui.entrada id="ficha-nombre" wire:model="nombre" autofocus required />
                    </x-ui.campo>
                    <x-ui.campo etiqueta="Teléfono" para="ficha-telefono" error="telefono">
                        <x-ui.entrada tipo="tel" id="ficha-telefono" wire:model="telefono" />
                    </x-ui.campo>
                    @if ($this->conDireccion)
                        <x-ui.campo etiqueta="Dirección" para="ficha-direccion" error="direccion" class="sm:col-span-2">
                            <x-ui.entrada id="ficha-direccion" wire:model="direccion" maxlength="300" />
                        </x-ui.campo>
                    @endif
                </div>
            </div>

            <x-slot:pie>
                <button type="button" wire:click="cancelarEdicion" class="fp-btn fp-btn-secundario">Cancelar</button>
                <button type="submit" class="fp-btn fp-btn-primario" wire:loading.attr="disabled" wire:target="guardarEdicion,foto">
                    <span wire:loading.remove wire:target="guardarEdicion">Guardar</span>
                    <span wire:loading wire:target="guardarEdicion">Guardando…</span>
                </button>
            </x-slot:pie>
        </x-panel.modal>
    @endif

    @if ($cambio !== '')
        @php($titulos = ['correo' => 'Cambiar correo', 'rol' => 'Cambiar rol', 'password' => 'Restablecer contraseña'])
        <x-panel.modal :titulo="$titulos[$cambio]" :subtitulo="$nombrePersona" cerrar="cerrarCambio" formulario="guardarCambio"
            ancho="max-w-xl" class="fp-formulario" data-modal="cambio-{{ $cambio }}">
            <div class="space-y-4">
                @if ($cambio === 'correo')
                    <p class="text-sm text-slate-600">Correo actual: <span class="font-medium text-slate-800 break-words">{{ $persona->email }}</span></p>
                    <x-ui.campo etiqueta="Correo nuevo" para="cambio-correo" error="email"
                        ayuda="Cambia cuando la persona lo confirme desde ese correo. Al actual le llega un aviso." requerido>
                        <x-ui.entrada tipo="email" id="cambio-correo" wire:model="correo_nuevo" autofocus required />
                    </x-ui.campo>
                @elseif ($cambio === 'rol')
                    <x-ui.campo etiqueta="Asignación" para="cambio-asignacion" error="asignacion" requerido>
                        <x-ui.selector id="cambio-asignacion" wire:model.live="asignacion">
                            @foreach ($this->roles as $rol)
                                <option value="{{ $rol['rol'] }}|{{ $rol['equipo'] }}">{{ $rol['etiqueta'] }}{{ $rol['distribuidora'] ? ' · '.$rol['distribuidora'] : '' }}</option>
                            @endforeach
                        </x-ui.selector>
                    </x-ui.campo>
                    <x-ui.campo etiqueta="Rol nuevo" para="cambio-rol" error="rol_nuevo" requerido>
                        <x-ui.selector id="cambio-rol" wire:model.live="rol_nuevo">
                            @foreach ($this->opcionesRol() as $valor => $etiqueta)
                                <option value="{{ $valor }}">{{ $etiqueta }}</option>
                            @endforeach
                        </x-ui.selector>
                    </x-ui.campo>

                    @if ($this->rolOrigen() === \App\Support\PersonalPlataforma::ADMIN_GENERAL)
                        <x-ui.campo etiqueta="Distribuidora" para="cambio-distribuidora" error="distribuidora_nueva" requerido>
                            <x-ui.selector id="cambio-distribuidora" wire:model="distribuidora_nueva" vacio="Elige una distribuidora" required>
                                @foreach ($this->distribuidorasActivas as $distribuidora)
                                    <option value="{{ $distribuidora->id }}">{{ $distribuidora->nombre_comercial ?: $distribuidora->razon_social }}</option>
                                @endforeach
                            </x-ui.selector>
                        </x-ui.campo>
                    @endif

                    @if ($this->esConversion())
                        <x-panel.alerta tipo="peligro" data-advertencia-rol>
                            @if ($this->rolOrigen() === \App\Support\PersonalPlataforma::ADMIN_GENERAL)
                                <p class="font-semibold">Dejará de ser admin general.</p>
                                <p class="mt-1">Perderá el acceso a la administración de FootwearPoint y solo trabajará en la distribuidora que elijas.</p>
                            @else
                                <p class="font-semibold">Tendrá acceso total a FootwearPoint.</p>
                                <p class="mt-1">Su acceso en la distribuidora quedará inactivo y dejará de trabajar ahí. Su historial se conserva y su dirección se borrará.</p>
                            @endif
                            <label class="mt-3 flex items-start gap-2 font-medium">
                                <input type="checkbox" wire:model="confirmar_consecuencias" class="mt-0.5">
                                <span>Entiendo las consecuencias de este cambio.</span>
                            </label>
                        </x-panel.alerta>
                        @error('confirmar_consecuencias') <p class="fp-campo-error" role="alert">{{ $message }}</p> @enderror
                    @endif
                @else
                    <fieldset class="space-y-2">
                        <legend class="fp-campo-etiqueta">¿Cómo va a entrar?</legend>
                        <label class="flex items-start gap-2 text-sm text-slate-700">
                            <input type="radio" wire:model="modo_password" value="temporal" class="mt-0.5">
                            <span>Contraseña temporal. La verás una sola vez, la anterior deja de servir y deberá cambiarla al entrar.</span>
                        </label>
                        <label class="flex items-start gap-2 text-sm text-slate-700">
                            <input type="radio" wire:model="modo_password" value="enlace" class="mt-0.5">
                            <span>Enlace por correo para que defina una nueva. Vence en 60 minutos.</span>
                        </label>
                        @error('modo_password') <p class="fp-campo-error" role="alert">{{ $message }}</p> @enderror
                    </fieldset>
                @endif

                <x-ui.campo etiqueta="Tu contraseña" para="cambio-confirmacion" error="password_confirmacion"
                    ayuda="Para confirmar que eres tú." requerido>
                    <x-ui.entrada tipo="password" id="cambio-confirmacion" wire:model="password_confirmacion" autocomplete="current-password" required />
                </x-ui.campo>
            </div>

            <x-slot:pie>
                <button type="button" wire:click="cerrarCambio" class="fp-btn fp-btn-secundario">Cancelar</button>
                <button type="submit" class="fp-btn fp-btn-primario" wire:loading.attr="disabled" wire:target="guardarCambio">
                    <span wire:loading.remove wire:target="guardarCambio">Confirmar</span>
                    <span wire:loading wire:target="guardarCambio">Guardando…</span>
                </button>
            </x-slot:pie>
        </x-panel.modal>
    @endif

    @if ($temporal = $this->passwordTemporalVisible())
        <x-panel.modal titulo="Contraseña temporal" subtitulo="Cópiala ahora: no se volverá a mostrar." data-modal="password-temporal">
            <p class="text-sm text-slate-600">Para <span class="font-medium text-slate-800">{{ $persona->email }}</span>. Deberá cambiarla la próxima vez que entre.</p>
            <code class="mt-3 block select-all break-all rounded-lg bg-fp-page px-4 py-3 text-center text-lg font-semibold tracking-wide text-fp-sidebar" data-password-temporal>{{ $temporal }}</code>
            <x-slot:pie>
                <button type="button" wire:click="cerrarPasswordTemporal" class="fp-btn fp-btn-primario">Ya la guardé</button>
            </x-slot:pie>
        </x-panel.modal>
    @endif
</div>
