<?php

use App\Rules\CorreoConDominioReal;
use App\Models\ClienteDirecto;
use App\Services\Auth\EnviarEnlaceRestablecerPanelAction;
use App\Services\Correo\IntentarEnviarCorreoAction;
use App\Services\Auth\GenerarPasswordTemporalAction;
use App\Services\Distribuidora\ActivarCuentaAccesoAction;
use App\Services\Distribuidora\GestionarClienteDirectoAction;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;
use App\Services\Distribuidora\EliminarPersonaAction;
use App\Services\Perfil\FotoPerfil;
use App\Support\MensajeError;

new #[Layout('layouts.panel')] #[Title('Clientes directos — FootwearPoint')] class extends Component {
    use \App\Livewire\Concerns\GestionaBajasDeAcceso;
    use WithFileUploads;

    private function despuesDeCambioDeAcceso(): void
    {
        $this->cargarClientesDirectos();
    }
    // TG-192: aviso del envio del enlace de restablecimiento.
    public ?string $avisoEnlace = null;
    public bool $avisoEnlaceEsError = false;

    public $clientesDirectos = [];
    #[\Livewire\Attributes\Url(except: '')]
    public string $buscar = '';
    public string $filtroEstado = '';
    #[Locked]
    public ?int $clienteEditandoId = null;
    public bool $mostrandoFormularioCliente = false;
    public string $cliente_nombre = '';
    public ?string $cliente_telefono = null;
    public ?string $cliente_email = null;
    public ?string $cliente_direccion_contacto = null;
    public string $cliente_estado = 'activo';

    /** Foto de perfil: la actual (URL) y la nueva (temporal de Livewire). */
    #[Locked]
    public ?string $cliente_foto_actual = null;
    public $cliente_foto = null;

    /** Eliminar definitivamente: ['tipo' => 'cliente', 'id' => int, 'nombre' => string]. */
    #[Locked]
    public ?array $porEliminar = null;
    public ?string $errorEliminar = null;

    // Cuenta para la app móvil (E3-07 / TG-133), igual que en revendedores.
    public ?string $cliente_cuenta_email_actual = null;
    public ?string $cliente_acceso_email = null;
    public string $cliente_acceso_password = '';
    public string $cliente_acceso_password_confirmation = '';

    public function mount(): void
    {
        $this->cargarClientesDirectos();
    }

    private function consultaClientes()
    {
        $q = ClienteDirecto::with('usuario')->orderBy('nombre');
        if ($this->filtroEstado !== '') {
            $q->where('estado', $this->filtroEstado);
        }
        if (trim($this->buscar) !== '') {
            $t = '%'.addcslashes(trim($this->buscar), '%_\\').'%';
            $q->where(fn ($w) => $w->where('nombre', 'like', $t)
                ->orWhere('telefono', 'like', $t)
                ->orWhere('email', 'like', $t)
                ->orWhereHas('usuario', fn ($u) => $u->where('email', 'like', $t)));
        }

        return $q;
    }

    private function cargarClientesDirectos(): void
    {
        $this->clientesDirectos = $this->consultaClientes()->get();
    }

    public function updatedBuscar(): void
    {
        $this->cargarClientesDirectos();
    }

    public function updatedFiltroEstado(): void
    {
        $this->cargarClientesDirectos();
    }

    /** Exporta a .xlsx los clientes directos con la búsqueda y el estado de la pantalla. */
    public function exportar()
    {
        \App\Services\Exportar\ExportarListadoXlsx::exigirPersonal(soloAdministrador: true);

        $filas = $this->consultaClientes()->with('usuario')
            ->limit(\App\Services\Exportar\ExportarListadoXlsx::MAX_FILAS + 1)->get()->map(fn (ClienteDirecto $c) => [
            $c->nombre, $c->telefono, $c->emailVisible(), $c->direccion_contacto,
            ucfirst((string) $c->estado), $c->usuario ? 'Sí' : 'No', $c->created_at?->format('d/m/Y'),
        ]);

        return app(\App\Services\Exportar\ExportarListadoXlsx::class)->descargar(
            'clientes-directos-'.now()->format('Ymd-His').'.xlsx', 'Clientes directos',
            ['Nombre', 'Teléfono', 'Correo', 'Dirección', 'Estado', 'Cuenta app', 'Alta'],
            $filas,
            array_filter(['Búsqueda' => trim($this->buscar) ?: null, 'Estado' => $this->filtroEstado !== '' ? ucfirst($this->filtroEstado) : null]),
        );
    }

    public function abrirFormularioCrearCliente(): void
    {
        $this->clienteEditandoId = null;
        $this->cliente_nombre = '';
        $this->cliente_telefono = null;
        $this->cliente_email = null;
        $this->cliente_direccion_contacto = null;
        $this->cliente_estado = 'activo';
        $this->limpiarAccesoCliente();
        $this->resetErrorBag();
        $this->mostrandoFormularioCliente = true;
    }

    public function abrirFormularioEditarCliente(int $id): void
    {
        $cliente = ClienteDirecto::with('usuario')->findOrFail($id);
        $this->clienteEditandoId = $cliente->id;
        $this->cliente_nombre = $cliente->nombre;
        $this->cliente_telefono = $cliente->telefono;
        $this->cliente_email = $cliente->email;
        $this->cliente_direccion_contacto = $cliente->direccion_contacto;
        $this->cliente_estado = $cliente->estado;
        $this->limpiarAccesoCliente();
        $this->cliente_cuenta_email_actual = $cliente->usuario?->email;
        $this->cliente_foto_actual = $cliente->foto_url;
        $this->resetErrorBag();
        $this->mostrandoFormularioCliente = true;
    }

    /** TG-192 (A3): le manda al cliente el enlace para cambiar su contrasena. */
    public function enviarEnlaceCliente(int $id): void
    {
        $cliente = ClienteDirecto::with('usuario')->findOrFail($id);
        $cuenta = $cliente->usuario;

        if (! $cuenta) {
            $this->avisoEnlace = 'Ese cliente todavia no tiene cuenta para la app.';
            $this->avisoEnlaceEsError = true;

            return;
        }

        try {
            $this->avisoEnlace = app(EnviarEnlaceRestablecerPanelAction::class)
                ->ejecutar($cuenta, 'cliente_directo', (int) $cliente->id);
            $this->avisoEnlaceEsError = false;
        } catch (ValidationException $e) {
            $this->avisoEnlace = $e->errors()['enlace'][0] ?? 'No se pudo enviar el enlace.';
            $this->avisoEnlaceEsError = true;
        }
    }

    /**
     * TG-193 (A4): respaldo del enlace por correo. Le pone una contraseña
     * temporal y se la muestra al personal UNA vez, para que se la dicte. La
     * persona tendra que cambiarla al entrar.
     */
    public function generarPasswordTemporalCliente(int $id): void
    {
        $cliente = ClienteDirecto::with('usuario')->findOrFail($id);
        $cuenta = $cliente->usuario;
        $entidadId = $cliente->id;

        if (! $cuenta) {
            $this->avisoEnlace = 'Primero hay que darle acceso a la app.';
            $this->avisoEnlaceEsError = true;

            return;
        }

        try {
            $password = app(GenerarPasswordTemporalAction::class)
                ->ejecutar($cuenta, 'cliente_directo', (int) $entidadId);
        } catch (ValidationException $e) {
            // La cuenta la comparte otra distribuidora: solo queda el enlace.
            $this->avisoEnlace = $e->errors()['password_temporal'][0]
                ?? 'No se pudo generar la contraseña temporal.';
            $this->avisoEnlaceEsError = true;

            return;
        }

        // En la sesion y solo por esta respuesta: una propiedad publica
        // del componente viajaria al navegador en cada accion siguiente.
        session()->now('aviso_password_temporal', 'Contraseña temporal para '.$cuenta->email.': '.$password
            .'. Anótala ahora, no se vuelve a mostrar. Se le pedirá cambiarla al entrar.');

        $this->avisoEnlace = null;
        $this->avisoEnlaceEsError = false;
    }


    /**
     * TG-189 (A11): reenviar el correo de confirmacion desde el mostrador,
     * cuando la persona dice que no le llego.
     */
    public function reenviarVerificacionCliente(int $id): void
    {
        $cliente = ClienteDirecto::with('usuario')->findOrFail($id);
        $cuenta = $cliente->usuario;

        if (! $cuenta) {
            $this->avisoEnlace = 'Primero hay que darle acceso a la app.';
            $this->avisoEnlaceEsError = true;

            return;
        }

        if ($cuenta->hasVerifiedEmail()) {
            $this->avisoEnlace = 'Ese correo ya esta confirmado.';
            $this->avisoEnlaceEsError = false;

            return;
        }

        $fallo = app(IntentarEnviarCorreoAction::class)->ejecutar(
            fn () => $cuenta->sendEmailVerificationNotification(),
            'confirmacion',
            ['usuario_id' => $cuenta->id, 'email' => $cuenta->email, 'tipo' => 'cliente directo'],
        );

        if ($fallo !== null) {
            $this->avisoEnlace = $fallo;
            $this->avisoEnlaceEsError = true;

            return;
        }

        $this->avisoEnlace = 'Le reenviamos el enlace de confirmacion a '.$cuenta->email.'.';
        $this->avisoEnlaceEsError = false;
    }

    public function cancelarFormularioCliente(): void
    {
        $this->mostrandoFormularioCliente = false;
        $this->clienteEditandoId = null;
        $this->limpiarAccesoCliente();
    }

    public function quitarFotoNuevaCliente(): void
    {
        $this->cliente_foto = null;
        $this->resetErrorBag('cliente_foto');
    }

    /** Abre el modal de «Eliminar definitivamente». */
    public function pedirEliminar(string $tipo, int $id): void
    {
        abort_unless($tipo === 'cliente', 404);

        $nombre = ClienteDirecto::findOrFail($id)->nombre;

        $this->porEliminar = ['tipo' => 'cliente', 'id' => $id, 'nombre' => (string) ($nombre ?: 'Sin nombre')];
        $this->errorEliminar = null;
    }

    public function cancelarEliminar(): void
    {
        $this->porEliminar = null;
        $this->errorEliminar = null;
    }

    public function eliminarDefinitivamente(): void
    {
        if ($this->porEliminar === null) {
            return;
        }

        ['id' => $id, 'nombre' => $nombre] = $this->porEliminar;

        try {
            app(EliminarPersonaAction::class)->clienteDirecto($id);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            $this->errorEliminar = 'Ya no existe: alguien más lo eliminó.';

            return;
        } catch (\Throwable $e) {
            $this->errorEliminar = MensajeError::paraUsuario($e, 'No se pudo eliminar.');

            return;
        }

        $this->porEliminar = null;
        $this->errorEliminar = null;
        $this->cargarClientesDirectos();
        $this->dispatch('guardado', mensaje: $nombre.' quedó eliminado definitivamente.');
    }

    public function guardarCliente(): void
    {
        $datos = $this->validate([
            'cliente_nombre' => ['required', 'string', 'max:150'],
            'cliente_telefono' => ['nullable', 'string', 'max:30'],
            'cliente_email' => ['nullable', 'email', new CorreoConDominioReal(), 'max:190'],
            'cliente_direccion_contacto' => ['nullable', 'string', 'max:300'],
            'cliente_foto' => ['nullable', ...FotoPerfil::reglas()],
            // E3-07: opcionales, pero si se llena uno se exige el otro.
            'cliente_acceso_email' => ['nullable', 'required_with:cliente_acceso_password', 'email', new CorreoConDominioReal(), 'max:190'],
            'cliente_acceso_password' => ['nullable', 'required_with:cliente_acceso_email', 'string', \Illuminate\Validation\Rules\Password::defaults(), 'max:255', 'same:cliente_acceso_password_confirmation'],
        ], [
            'cliente_acceso_password.max' => 'La contraseña no puede tener más de 255 caracteres.',
            'cliente_acceso_email.required_with' => 'Para dar acceso a la app escribe también el correo.',
            'cliente_acceso_password.required_with' => 'Para dar acceso a la app escribe también la contraseña.',
            'cliente_acceso_password.min' => 'La contraseña debe tener al menos 8 caracteres.',
            'cliente_acceso_password.same' => 'La confirmación de la contraseña no coincide.',
            ...FotoPerfil::mensajes('cliente_foto'),
        ]);

        $payload = [
            'nombre' => $datos['cliente_nombre'],
            'telefono' => $datos['cliente_telefono'],
            'direccion_contacto' => $datos['cliente_direccion_contacto'],
            'estado' => $this->cliente_estado,
        ];

        // Si ya tiene cuenta, su correo es el de acceso y vive en la cuenta:
        // desde aquí no se toca (D4, TG-216).
        if ($this->cliente_cuenta_email_actual === null) {
            $payload['email'] = $datos['cliente_email'];
        }

        $accion = app(GestionarClienteDirectoAction::class);

        // Una sola transacción: si la cuenta falla (correo repetido), tampoco
        // se crea el cliente, para no dejar un duplicado en la lista.
        try {
            $cliente = DB::transaction(function () use ($accion, $payload, $datos) {
                $cliente = $this->clienteEditandoId
                    ? $accion->actualizar(ClienteDirecto::findOrFail($this->clienteEditandoId), $payload)
                    : $accion->crear($payload);

                if (filled($datos['cliente_acceso_email']) && $this->cliente_cuenta_email_actual === null) {
                    app(ActivarCuentaAccesoAction::class)->paraClienteDirecto(
                        $cliente->fresh(),
                        $datos['cliente_acceso_email'],
                        $datos['cliente_acceso_password'],
                    );
                }

                return $cliente;
            });
        } catch (ValidationException $e) {
            foreach ($e->errors() as $campo => $mensajes) {
                $this->addError('cliente_' . $campo, $mensajes[0]);
            }

            return;
        }

        if ($this->cliente_foto) {
            $cliente = ClienteDirecto::findOrFail($cliente->id);
            $url = app(FotoPerfil::class)->guardar($this->cliente_foto, 'clientes-directos', $cliente->foto_url);
            $cliente->forceFill(['foto_url' => $url])->save();
        }

        $this->mostrandoFormularioCliente = false;
        $this->clienteEditandoId = null;
        $this->limpiarAccesoCliente();
        $this->cargarClientesDirectos();
        $this->dispatch('guardado', mensaje: 'Cliente directo guardado correctamente.');
    }

    /** Las contraseñas no se quedan guardadas en el estado de la pantalla. */
    private function limpiarAccesoCliente(): void
    {
        $this->cliente_cuenta_email_actual = null;
        $this->cliente_foto_actual = null;
        $this->cliente_foto = null;
        $this->cliente_acceso_email = null;
        $this->cliente_acceso_password = '';
        $this->cliente_acceso_password_confirmation = '';
    }

};
?>

<div class="mx-auto w-full max-w-7xl space-y-6">
    <x-panel.encabezado eyebrow="Distribuidora" titulo="Clientes directos" nivel="h1"
        subtitulo="Personas que compran en mostrador o en la app." class="mb-6" />

    <x-personas.tarjeta titulo="Clientes directos" data-lista="clientes-directos">
        <x-slot:acciones>
            @if (\Illuminate\Support\Facades\Route::has('clientes.importar') && app(\App\Services\Auth\PermisoModuloService::class)->puede(auth()->user(), \App\Services\Auth\PermisoModuloService::CLIENTES_IMPORTAR))
                        <a href="{{ route('clientes.importar') }}" wire:navigate class="fp-btn fp-btn-fantasma" data-importar-clientes>Importar CSV/Excel</a>
                    @endif
            <button type="button" wire:click="abrirFormularioCrearCliente" class="fp-btn fp-btn-primario">+ Nuevo cliente</button>
        </x-slot:acciones>
        <x-slot:filtros data-filtros-clientes>
            <x-ui.buscador wire:model.live.debounce.300ms="buscar" placeholder="Buscar nombre, teléfono o correo" etiqueta="Buscar clientes" class="min-w-0 flex-1" />
            <select wire:model.live="filtroEstado" aria-label="Filtrar por estado" class="rounded-lg text-sm">
                <option value="">Todos los estados</option>
                <option value="activo">Activo</option>
                <option value="inactivo">Inactivo</option>
            </select>
            <button type="button" wire:click="exportar" data-exportar="clientes-directos" class="fp-btn fp-btn-secundario"
                wire:loading.attr="disabled" wire:target="exportar">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v12m0 0l-4-4m4 4l4-4M4 20h16"/></svg>
                <span wire:loading.remove wire:target="exportar">Exportar Excel</span>
                <span wire:loading wire:target="exportar">Generando…</span>
            </button>
        </x-slot:filtros>
        {{-- TG-192: resultado del envio del enlace de restablecimiento.
             La contrasena temporal se saca de la sesion, no de una propiedad
             del componente: esas viajan al navegador en cada accion
             posterior. Asi se ve una vez y no queda guardada en ningun lado. --}}
        @if (session('aviso_password_temporal'))
            <div class="mb-4 rounded-lg border border-fp-badge-warning-fg/20 bg-fp-badge-warning-bg px-4 py-3 text-sm text-fp-badge-warning-fg">
                {{ session('aviso_password_temporal') }}
            </div>
        @endif
        @if ($avisoEnlace)
            <div class="mb-4 rounded-lg border px-4 py-3 text-sm {{ $avisoEnlaceEsError ? 'border-fp-danger/20 bg-fp-danger-soft text-fp-badge-danger-fg' : 'border-fp-badge-success-fg/20 bg-fp-badge-success-bg text-fp-badge-success-fg' }}">
                {{ $avisoEnlace }}
            </div>
        @endif
        <table class="fp-tabla-personas">
            <thead>
                <tr>
                    <th scope="col" colspan="2">Nombre</th>
                    <th scope="col">Teléfono</th>
                    <th scope="col">Correo</th>
                    <th scope="col">Estado</th>
                    <th scope="col">App</th>
                    <th scope="col" class="text-right">Acciones</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($clientesDirectos as $cliente)
                    <tr class="fp-fila-persona" wire:key="cliente-{{ $cliente->id }}">
                        <td class="fp-celda-avatar w-11 pr-0"><x-ui.avatar :url="$cliente->foto_url" :nombre="$cliente->nombre" /></td>
                        <td class="fp-celda-nombre">{{ $cliente->nombre }}</td>
                        <td class="whitespace-nowrap" data-etiqueta="Teléfono">{{ $cliente->telefono ?: '—' }}</td>
                        <td class="fp-celda-correo" data-etiqueta="Correo">
                            {{ $cliente->emailVisible() ?: '—' }}
                            <x-ui.estado-correo :usuario="$cliente->usuario" />
                        </td>
                        <td><x-personas.estado :estado="$cliente->estado" /></td>
                        <td>
                            @if ($cliente->usuario_id)
                                <span class="inline-flex items-center whitespace-nowrap px-2.5 py-1 rounded-full text-xs font-medium bg-fp-badge-success-bg text-fp-badge-success-fg">Con cuenta</span>
                            @else
                                <span class="whitespace-nowrap text-xs text-slate-500">Sin cuenta</span>
                            @endif
                        </td>
                        <td class="fp-celda-acciones">
                            <div class="fp-acciones-persona">
                                <x-personas.accion icono="lapiz" etiqueta="Editar" :persona="$cliente->nombre"
                                    wire:click="abrirFormularioEditarCliente({{ $cliente->id }})" />
                                @if ($cliente->estado === 'activo')
                                    <x-personas.accion icono="baja" etiqueta="Dar de baja" :persona="$cliente->nombre" tono="peligro"
                                        wire:click="darDeBaja('cliente', {{ $cliente->id }})"
                                        wire:confirm="¿Dar de baja a {{ $cliente->nombre }}? Ya no podrá entrar a la app; su historial se conserva."
                                        data-baja="cliente-{{ $cliente->id }}" />
                                @else
                                    <x-personas.accion icono="reactivar" etiqueta="Reactivar" :persona="$cliente->nombre" tono="exito"
                                        wire:click="reactivar('cliente', {{ $cliente->id }})"
                                        wire:confirm="¿Reactivar el acceso de {{ $cliente->nombre }}?" />
                                @endif
                                <x-personas.mas-acciones :persona="$cliente->nombre">
                                    {{-- TG-192: solo tiene sentido si ya tiene cuenta en la app. --}}
                                    @if ($cliente->usuario_id)
                                        <x-personas.accion menu icono="enlace" etiqueta="Enviar enlace" :persona="$cliente->nombre"
                                            wire:click="enviarEnlaceCliente({{ $cliente->id }})" wire:loading.attr="disabled" />
                                        {{-- TG-189: solo si todavía no confirma su correo. --}}
                                        @if (! $cliente->usuario?->hasVerifiedEmail())
                                            <x-personas.accion menu icono="correo" etiqueta="Reenviar confirmación" :persona="$cliente->nombre"
                                                wire:click="reenviarVerificacionCliente({{ $cliente->id }})" wire:loading.attr="disabled" />
                                        @endif
                                        <x-personas.accion menu icono="llave" etiqueta="Contraseña temporal" :persona="$cliente->nombre"
                                            wire:click="generarPasswordTemporalCliente({{ $cliente->id }})" wire:loading.attr="disabled" />
                                    @endif
                                    @if (\Illuminate\Support\Facades\Route::has('clientes.historial.show'))
                                        <x-personas.accion menu icono="historial" etiqueta="Historial" :persona="$cliente->nombre"
                                            :href="route('clientes.historial.show', ['cliente_directo', $cliente->id])" wire:navigate />
                                    @endif
                                    <div class="fp-menu-separador" role="separator"></div>
                                    <x-personas.accion menu icono="basura" etiqueta="Eliminar" :persona="$cliente->nombre" tono="peligro"
                                        wire:click="pedirEliminar('cliente', {{ $cliente->id }})" data-eliminar="cliente-{{ $cliente->id }}" />
                                </x-personas.mas-acciones>
                            </div>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </x-personas.tarjeta>

    @if ($mostrandoFormularioCliente)
        <x-panel.modal :titulo="$clienteEditandoId ? 'Editar cliente directo' : 'Nuevo cliente directo'"
            subtitulo="Sus datos de contacto, foto y, si quieres, acceso a la app."
            cerrar="cancelarFormularioCliente" formulario="guardarCliente" ancho="max-w-xl" class="fp-formulario" data-modal="cliente">
            <div class="space-y-4">
                <x-personas.foto modelo="cliente_foto" :archivo="$cliente_foto" :actual="$cliente_foto_actual" :nombre="$cliente_nombre"
                    quitar="quitarFotoNuevaCliente" id="clientes-cliente-foto" datos="cliente" />
                <div class="fp-campo">
                    <label for="clientes-cliente-nombre" class="block text-sm font-medium text-slate-700 mb-1">Nombre</label>
                    <input id="clientes-cliente-nombre" type="text" wire:model="cliente_nombre" autofocus required class="w-full rounded-lg bg-white text-sm shadow-sm focus:ring-fp-primary">
                    @error('cliente_nombre') <span class="text-fp-badge-danger-fg text-xs">{{ $message }}</span> @enderror
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="fp-campo">
                        <label for="clientes-cliente-telefono" class="block text-sm font-medium text-slate-700 mb-1">Teléfono</label>
                        <input id="clientes-cliente-telefono" type="text" wire:model="cliente_telefono" class="w-full rounded-lg bg-white text-sm shadow-sm focus:ring-fp-primary">
                    </div>
                    <div class="fp-campo">
                        <label for="clientes-cliente-email" class="block text-sm font-medium text-slate-700 mb-1">Correo</label>
                        @if ($cliente_cuenta_email_actual !== null)
                            <input id="clientes-cliente-email" type="email" value="{{ $cliente_cuenta_email_actual }}" disabled
                                class="w-full rounded-lg bg-slate-100 text-sm text-slate-500 shadow-sm">
                            <p class="mt-1 text-xs text-fp-text-muted">Es su correo para entrar a la app.</p>
                        @else
                            <input id="clientes-cliente-email" type="email" wire:model="cliente_email" class="w-full rounded-lg bg-white text-sm shadow-sm focus:ring-fp-primary">
                            @error('cliente_email') <span class="text-fp-badge-danger-fg text-xs" role="alert">{{ $message }}</span> @enderror
                        @endif
                    </div>
                </div>
                <div class="fp-campo">
                    <label for="clientes-cliente-direccion-contacto" class="block text-sm font-medium text-slate-700 mb-1">Dirección de contacto</label>
                    <input id="clientes-cliente-direccion-contacto" type="text" wire:model="cliente_direccion_contacto" class="w-full rounded-lg bg-white text-sm shadow-sm focus:ring-fp-primary">
                </div>
                {{-- E3-07 (TG-133): cuenta para entrar a la app móvil --}}
                <div class="border-t border-slate-200 pt-4">
                    <h3 class="text-sm font-semibold text-slate-700 mb-1">Acceso a la app</h3>
                    @if ($cliente_cuenta_email_actual)
                        <p class="text-sm text-slate-600">
                            Ya tiene cuenta: <span class="font-medium">{{ $cliente_cuenta_email_actual }}</span>
                        </p>
                    @else
                        <p class="text-xs text-slate-500 mb-3">
                            Opcional. Si le das un correo y una contraseña, podrá entrar a la app móvil con ellos.
                        </p>
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div class="col-span-2">
                                <label for="clientes-cliente-acceso-email" class="block text-sm font-medium text-slate-700 mb-1">Correo para entrar a la app</label>
                                <input id="clientes-cliente-acceso-email" type="email" wire:model="cliente_acceso_email" autocomplete="off" class="w-full rounded-lg bg-white text-sm shadow-sm focus:ring-fp-primary">
                                @error('cliente_acceso_email') <span class="text-fp-badge-danger-fg text-xs">{{ $message }}</span> @enderror
                            </div>
                            <div class="fp-campo">
                                <label for="clientes-cliente-acceso-password" class="block text-sm font-medium text-slate-700 mb-1">Contraseña</label>
                                <input id="clientes-cliente-acceso-password" type="password" wire:model="cliente_acceso_password" autocomplete="new-password" class="w-full rounded-lg bg-white text-sm shadow-sm focus:ring-fp-primary">
                                @error('cliente_acceso_password') <span class="text-fp-badge-danger-fg text-xs">{{ $message }}</span> @enderror
                            </div>
                            <div class="fp-campo">
                                <label for="clientes-cliente-acceso-password-confirmation" class="block text-sm font-medium text-slate-700 mb-1">Confirmar contraseña</label>
                                <input id="clientes-cliente-acceso-password-confirmation" type="password" wire:model="cliente_acceso_password_confirmation" autocomplete="new-password" class="w-full rounded-lg bg-white text-sm shadow-sm focus:ring-fp-primary">
                            </div>
                        </div>
                    @endif
                </div>
                @if ($clienteEditandoId)
                    <div class="fp-campo">
                        <label for="clientes-cliente-estado" class="block text-sm font-medium text-slate-700 mb-1">Estado</label>
                        <select id="clientes-cliente-estado" wire:model="cliente_estado" class="w-full rounded-md max-w-xs">
                            <option value="activo">Activo</option>
                            <option value="inactivo">Inactivo</option>
                        </select>
                    </div>
                @endif
            </div>
            <x-slot:pie>
                <button type="button" wire:click="cancelarFormularioCliente" class="fp-btn fp-btn-secundario">Cancelar</button>
                <button type="submit" class="fp-btn fp-btn-primario" wire:loading.attr="disabled" wire:target="guardarCliente,cliente_foto">
                    <span wire:loading.remove wire:target="guardarCliente">Guardar</span>
                    <span wire:loading wire:target="guardarCliente">Guardando…</span>
                </button>
            </x-slot:pie>
        </x-panel.modal>
    @endif

    @if ($porEliminar)
        <x-personas.modal-eliminar :nombre="$porEliminar['nombre']" :error="$errorEliminar" />
    @endif
</div>
