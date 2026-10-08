<?php

use App\Exceptions\OperacionInvalidaException;
use App\Models\Distribuidora;
use App\Models\PlanSuscripcion;
use App\Models\Suscripcion;
use App\Services\Distribuidora\AprobacionDistribuidoraException;
use App\Services\Distribuidora\AprobarDistribuidoraAction;
use App\Services\Distribuidora\CambioEstadoDistribuidora;
use App\Services\Distribuidora\CrearDistribuidoraAction;
use App\Services\Distribuidora\DatosSolicitudDistribuidoraAction;
use App\Services\Distribuidora\ReactivarDistribuidoraAction;
use App\Services\Distribuidora\RechazarDistribuidoraAction;
use App\Services\Distribuidora\SuspenderDistribuidoraAction;
use App\Support\MensajeError;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts.admin')] #[Title('Distribuidoras — Admin')] class extends Component {
    public string $filtroEstado = '';
    public string $mensaje = '';

    // Asignar suscripción
    public bool $mostrarSuscripcion = false;
    public ?int $distribuidoraSuscripcionId = null;
    public string $distribuidoraSuscripcionNombre = '';
    public string $plan_id = '';
    public string $lineas_extra_contratadas = '0';
    public string $meses = '1';
    public bool $renovacion_automatica = true;

    public bool $mostrandoFormularioCrear = false;

    public string $nuevo_nombre_comercial = '';
    public string $nuevo_razon_social = '';
    public string $nuevo_rfc = '';
    public string $nuevo_slug = '';
    public string $nuevo_subdominio = '';
    public string $nuevo_email_publico = '';
    public string $nuevo_telefono_publico = '';
    public string $nuevo_direccion_publica = '';
    public string $nuevo_descripcion_publica = '';
    public string $nuevo_horario_publico = '';
    public bool $nuevo_marketplace_visible = true;
    public bool $nuevo_activar_ya = true; // activa al crear (admin)

    // Administrador de la distribuidora (obligatorio, TG-194)
    public string $admin_nombre = '';
    public string $admin_email = '';
    public string $admin_password = '';

    // Revisar los datos antes de aprobar o rechazar (TG-195)
    public ?int $distribuidoraDetalleId = null;

    // Rechazar con motivo (TG-195)
    public ?int $distribuidoraRechazoId = null;
    public string $distribuidoraRechazoNombre = '';
    public string $motivo_rechazo = '';

    public function mount()
    {
        if (!Auth::check()) {
            return $this->redirect(route('login'), navigate: true);
        }

        setPermissionsTeamId(0);

        if (!Auth::user()->hasRole('admin_general')) {
            abort(403, 'Solo admin general.');
        }
    }

    public function abrirFormularioCrear(): void
    {
        $this->resetValidation();
        $this->mensaje = '';
        $this->nuevo_nombre_comercial = '';
        $this->nuevo_razon_social = '';
        $this->nuevo_rfc = '';
        $this->nuevo_slug = '';
        $this->nuevo_subdominio = '';
        $this->nuevo_email_publico = '';
        $this->nuevo_telefono_publico = '';
        $this->nuevo_direccion_publica = '';
        $this->nuevo_descripcion_publica = '';
        $this->nuevo_horario_publico = '';
        $this->nuevo_marketplace_visible = true;
        $this->nuevo_activar_ya = true;
        $this->admin_nombre = '';
        $this->admin_email = '';
        $this->admin_password = '';
        $this->mostrandoFormularioCrear = true;
        $this->mostrarSuscripcion = false;
    }

    public function cancelarCrear(): void
    {
        $this->mostrandoFormularioCrear = false;
    }

    public function updatedNuevoNombreComercial($value): void
    {
        if ($this->nuevo_slug === '' || $this->nuevo_slug === Str::slug($this->nuevo_nombre_comercial)) {
            // no auto-overwrite if user typed slug manually after first fill — simple: always suggest
        }
        $base = Str::slug($value);
        $this->nuevo_slug = $base;
        if ($this->nuevo_subdominio === '') {
            $this->nuevo_subdominio = $base;
        }
    }

    public function crearDistribuidora(): void
    {
        $this->mensaje = '';

        // Se normaliza antes de validar, para que formato y unicidad se
        // revisen sobre lo mismo que se va a guardar.
        $this->nuevo_rfc = CrearDistribuidoraAction::normalizarRfc($this->nuevo_rfc) ?? '';

        $this->validate([
            'nuevo_nombre_comercial' => ['required', 'string', 'max:150'],
            'nuevo_razon_social' => ['nullable', 'string', 'max:200'],
            // RFC mexicano: 12 caracteres persona moral, 13 persona física.
            'nuevo_rfc' => ['nullable', 'string', 'regex:/^[A-ZÑ&]{3,4}\d{6}[A-Z0-9]{3}$/u', Rule::unique('distribuidoras', 'rfc')],
            'nuevo_slug' => ['required', 'string', 'max:120', 'unique:distribuidoras,slug', 'alpha_dash'],
            'nuevo_subdominio' => ['nullable', 'string', 'max:80', 'alpha_dash', Rule::unique('distribuidoras', 'subdominio')],
            'nuevo_email_publico' => ['nullable', 'email', 'max:190'],
            'nuevo_telefono_publico' => ['nullable', 'string', 'max:30'],
            'nuevo_direccion_publica' => ['nullable', 'string', 'max:300'],
            'nuevo_descripcion_publica' => ['nullable', 'string'],
            'nuevo_horario_publico' => ['nullable', 'string', 'max:300'],
            // TG-194: el administrador de la tienda es obligatorio.
            'admin_nombre' => ['required', 'string', 'max:150'],
            'admin_email' => ['required', 'email', 'max:190', 'unique:usuarios,email'],
            'admin_password' => ['required', 'string', 'min:8'],
        ], [
            'nuevo_nombre_comercial.required' => 'El nombre comercial es obligatorio.',
            'nuevo_nombre_comercial.max' => 'El nombre comercial no puede pasar de 150 caracteres.',
            'nuevo_razon_social.max' => 'La razón social no puede pasar de 200 caracteres.',
            'nuevo_rfc.regex' => 'El RFC no tiene un formato válido (12 caracteres persona moral o 13 persona física).',
            'nuevo_rfc.unique' => 'Ese RFC ya está registrado en otra distribuidora.',
            'nuevo_slug.required' => 'El slug es obligatorio.',
            'nuevo_slug.max' => 'El slug no puede pasar de 120 caracteres.',
            'nuevo_slug.unique' => 'Ese slug ya lo usa otra distribuidora.',
            'nuevo_slug.alpha_dash' => 'El slug solo puede tener letras, números, guiones y guiones bajos.',
            'nuevo_subdominio.max' => 'El subdominio no puede pasar de 80 caracteres.',
            'nuevo_subdominio.alpha_dash' => 'El subdominio solo puede tener letras, números, guiones y guiones bajos.',
            'nuevo_subdominio.unique' => 'Ese subdominio ya lo usa otra distribuidora.',
            'nuevo_email_publico.email' => 'El email público no es válido.',
            'nuevo_email_publico.max' => 'El email público no puede pasar de 190 caracteres.',
            'nuevo_telefono_publico.max' => 'El teléfono público no puede pasar de 30 caracteres.',
            'nuevo_direccion_publica.max' => 'La dirección pública no puede pasar de 300 caracteres.',
            'nuevo_horario_publico.max' => 'El horario público no puede pasar de 300 caracteres.',
            'admin_nombre.required' => 'El nombre del administrador es obligatorio.',
            'admin_nombre.max' => 'El nombre del administrador no puede pasar de 150 caracteres.',
            'admin_email.required' => 'El correo del administrador es obligatorio.',
            'admin_email.email' => 'El correo del administrador no es válido.',
            'admin_email.max' => 'El correo del administrador no puede pasar de 190 caracteres.',
            'admin_email.unique' => 'Ese correo ya tiene una cuenta.',
            'admin_password.required' => 'La contraseña del administrador es obligatoria.',
            'admin_password.min' => 'La contraseña debe tener al menos 8 caracteres.',
        ]);

        try {
            app(CrearDistribuidoraAction::class)->ejecutar(
                [
                    'nombre_comercial' => $this->nuevo_nombre_comercial,
                    'razon_social' => $this->nuevo_razon_social,
                    'rfc' => $this->nuevo_rfc,
                    'slug' => $this->nuevo_slug,
                    'subdominio' => $this->nuevo_subdominio,
                    'descripcion_publica' => $this->nuevo_descripcion_publica,
                    'direccion_publica' => $this->nuevo_direccion_publica,
                    'telefono_publico' => $this->nuevo_telefono_publico,
                    'email_publico' => $this->nuevo_email_publico,
                    'horario_publico' => $this->nuevo_horario_publico,
                    'marketplace_visible' => $this->nuevo_marketplace_visible,
                ],
                [
                    'nombre' => $this->admin_nombre,
                    'email' => $this->admin_email,
                    'password' => $this->admin_password,
                ],
                $this->nuevo_activar_ya,
            );
        } catch (ValidationException $e) {
            // TG-224 (G3): los mensajes de validación de la acción ya están
            // pensados para el usuario; cada uno va a su campo del formulario.
            $this->mensaje = '';
            foreach ($e->errors() as $campo => $mensajes) {
                $this->addError(property_exists($this, $campo) ? $campo : 'nuevo_nombre_comercial', $mensajes[0]);
            }
            return;
        } catch (\Throwable $e) {
            // TG-224 (G3): el detalle técnico va al log, nunca a la pantalla.
            $this->mensaje = '';
            $this->addError('nuevo_nombre_comercial', MensajeError::paraUsuario($e, 'No se pudo crear la distribuidora. Intenta de nuevo.'));
            return;
        }

        $this->admin_password = '';
        $this->mostrandoFormularioCrear = false;
        $this->mensaje = 'Distribuidora creada correctamente.';
    }

    public function getDistribuidorasProperty()
    {
        $query = Distribuidora::query()
            ->with([
                'suscripciones' => function ($q) {
                    $q->where('estado', 'activa')->latest('id');
                },
            ])
            ->orderByDesc('id');

        if ($this->filtroEstado !== '') {
            $query->where('estado', $this->filtroEstado);
        }

        return $query->get();
    }

    public function getPlanesActivosProperty()
    {
        return PlanSuscripcion::where('activo', true)->orderBy('precio_base_mensual')->get();
    }

    public function aprobar(int $id)
    {
        $distribuidora = Distribuidora::findOrFail($id);

        try {
            $cambio = app(AprobarDistribuidoraAction::class)->ejecutar($distribuidora);
        } catch (AprobacionDistribuidoraException $e) {
            $this->mensaje = $e->esNoPendiente()
                ? 'Solo se pueden aprobar distribuidoras pendientes.'
                : 'No hay planes configurados.';
            return;
        }

        $this->mensaje = $this->conAviso($cambio, "Distribuidora «{$distribuidora->nombre_comercial}» aprobada.");
    }

    /** TG-195: muestra los datos de la distribuidora para revisarla. */
    public function verDatos(int $id): void
    {
        $this->distribuidoraDetalleId = Distribuidora::findOrFail($id)->id;
        $this->mensaje = '';
    }

    public function cerrarDatos(): void
    {
        $this->distribuidoraDetalleId = null;
    }

    /** Datos del panel "Ver datos" (los mismos que da la API). */
    public function getDetalleProperty(): ?array
    {
        if ($this->distribuidoraDetalleId === null) {
            return null;
        }

        $distribuidora = Distribuidora::find($this->distribuidoraDetalleId);

        return $distribuidora ? app(DatosSolicitudDistribuidoraAction::class)->ejecutar($distribuidora) : null;
    }

    /** TG-195: abre la ventana para escribir el motivo del rechazo. */
    public function abrirRechazo(int $id): void
    {
        $distribuidora = Distribuidora::findOrFail($id);

        if ($distribuidora->estado !== 'pendiente') {
            $this->mensaje = RechazarDistribuidoraAction::MENSAJE_NO_PENDIENTE;
            return;
        }

        $this->resetValidation();
        $this->mensaje = '';
        $this->mostrarSuscripcion = false;
        $this->mostrandoFormularioCrear = false;
        $this->distribuidoraRechazoId = $distribuidora->id;
        $this->distribuidoraRechazoNombre = $distribuidora->nombre_comercial;
        $this->motivo_rechazo = '';
    }

    public function cancelarRechazo(): void
    {
        $this->resetValidation('motivo_rechazo');
        $this->distribuidoraRechazoId = null;
        $this->distribuidoraRechazoNombre = '';
        $this->motivo_rechazo = '';
    }

    public function rechazar(): void
    {
        $this->mensaje = '';

        $this->validate([
            'motivo_rechazo' => ['required', 'string', 'max:' . RechazarDistribuidoraAction::LARGO_MAXIMO_MOTIVO],
        ], [
            'motivo_rechazo.required' => RechazarDistribuidoraAction::MENSAJE_MOTIVO_OBLIGATORIO,
            'motivo_rechazo.max' => RechazarDistribuidoraAction::MENSAJE_MOTIVO_LARGO,
        ]);

        try {
            $cambio = app(RechazarDistribuidoraAction::class)->ejecutar(
                Distribuidora::findOrFail($this->distribuidoraRechazoId),
                $this->motivo_rechazo,
            );
        } catch (ValidationException $e) {
            $this->addError('motivo_rechazo', collect($e->errors())->flatten()->first() ?? RechazarDistribuidoraAction::MENSAJE_MOTIVO_OBLIGATORIO);
            return;
        } catch (OperacionInvalidaException $e) {
            // Ya no está pendiente (otro admin la aprobó o rechazó antes).
            $this->cancelarRechazo();
            $this->mensaje = $e->getMessage();
            return;
        } catch (\Throwable $e) {
            // TG-224 (G3): el detalle técnico va al log, nunca a la pantalla.
            $this->addError('motivo_rechazo', MensajeError::paraUsuario($e, 'No se pudo rechazar la distribuidora. Intenta de nuevo.'));
            return;
        }

        $this->cancelarRechazo();
        $this->mensaje = $this->conAviso($cambio, "Distribuidora «{$cambio->distribuidora->nombre_comercial}» rechazada.");
    }

    /**
     * TG-196 (G5) — Suspende una distribuidora activa: conserva sus datos,
     * pero ni su personal ni sus revendedores y clientes pueden operar hasta
     * reactivarla. Se le avisa por correo.
     */
    public function suspender(int $id)
    {
        try {
            $cambio = app(SuspenderDistribuidoraAction::class)->ejecutar(Distribuidora::findOrFail($id));
        } catch (OperacionInvalidaException $e) {
            $this->mensaje = $e->getMessage();
            return;
        } catch (\Throwable $e) {
            $this->mensaje = MensajeError::paraUsuario($e, 'No se pudo suspender la distribuidora. Intenta de nuevo.');
            return;
        }

        $this->mensaje = $this->conAviso($cambio, "Distribuidora «{$cambio->distribuidora->nombre_comercial}» suspendida.");
    }

    /** TG-196 (G5) — Reactiva una distribuidora suspendida y le avisa por correo. */
    public function reactivar(int $id)
    {
        try {
            $cambio = app(ReactivarDistribuidoraAction::class)->ejecutar(Distribuidora::findOrFail($id));
        } catch (OperacionInvalidaException $e) {
            $this->mensaje = $e->getMessage();
            return;
        } catch (\Throwable $e) {
            $this->mensaje = MensajeError::paraUsuario($e, 'No se pudo reactivar la distribuidora. Intenta de nuevo.');
            return;
        }

        $this->mensaje = $this->conAviso($cambio, "Distribuidora «{$cambio->distribuidora->nombre_comercial}» reactivada.");
    }

    /** TG-196 (G5): si el correo no salió, el cambio quedó, pero se avisa al admin. */
    private function conAviso(CambioEstadoDistribuidora $cambio, string $mensaje): string
    {
        return $cambio->avisoEnviado
            ? $mensaje
            : $mensaje . ' No se pudo enviar el aviso por correo a la distribuidora.';
    }

    public function toggleMarketplace(int $id)
    {
        $d = Distribuidora::findOrFail($id);

        if ($d->estado !== 'activa' && !$d->marketplace_visible) {
            $this->mensaje = 'Solo distribuidoras activas pueden ser visibles en marketplace.';
            return;
        }

        $d->update(['marketplace_visible' => !$d->marketplace_visible]);
        $estado = $d->marketplace_visible ? 'visible' : 'oculta';
        $this->mensaje = "Marketplace: «{$d->nombre_comercial}» ahora está {$estado}.";
    }

    public function abrirSuscripcion(int $id)
    {
        $d = Distribuidora::with([
            'suscripciones' => function ($q) {
                $q->where('estado', 'activa')->latest('id');
            },
        ])->findOrFail($id);

        if (!in_array($d->estado, ['activa', 'suspendida'])) {
            $this->mensaje = 'Solo se puede asignar suscripción a distribuidoras activas o suspendidas.';
            return;
        }

        $this->distribuidoraSuscripcionId = $d->id;
        $this->distribuidoraSuscripcionNombre = $d->nombre_comercial;

        $activa = $d->suscripciones->first();

        if ($activa) {
            // Ya tiene plan: precargar datos para cambiar
            $this->plan_id = (string) $activa->plan_id;
            $this->lineas_extra_contratadas = (string) $activa->lineas_extra_contratadas;
            $this->meses = '1';
            $this->renovacion_automatica = (bool) $activa->renovacion_automatica;
        } else {
            // No tiene plan: formulario vacío
            $this->plan_id = '';
            $this->lineas_extra_contratadas = '0';
            $this->meses = '1';
            $this->renovacion_automatica = true;
        }

        $this->mostrarSuscripcion = true;
    }

    public function cancelarSuscripcion()
    {
        $this->mostrarSuscripcion = false;
        $this->distribuidoraSuscripcionId = null;
    }

    public function guardarSuscripcion()
    {
        $this->validate([
            'plan_id' => 'required|exists:planes_suscripcion,id',
            'lineas_extra_contratadas' => 'nullable|integer|min:0',
            'meses' => 'required|integer|min:1|max:24',
        ]);

        $distribuidora = Distribuidora::findOrFail($this->distribuidoraSuscripcionId);
        $plan = PlanSuscripcion::findOrFail($this->plan_id);

        if (!$plan->activo) {
            $this->mensaje = 'El plan seleccionado no está activo.';
            return;
        }

        Suscripcion::withoutGlobalScopes()
            ->where('distribuidora_id', $distribuidora->id)
            ->where('estado', 'activa')
            ->update([
                'estado' => 'cancelada',
                'fecha_fin' => now()->toDateString(),
            ]);

        Suscripcion::withoutGlobalScopes()->create([
            'distribuidora_id' => $distribuidora->id,
            'plan_id' => $plan->id,
            'fecha_inicio' => now()->toDateString(),
            'fecha_fin' => now()->addMonths((int) $this->meses)->toDateString(),
            'estado' => 'activa',
            'precio_base_contratado' => $plan->precio_base_mensual,
            'lineas_incluidas_contratadas' => $plan->lineas_incluidas,
            'precio_linea_extra_contratado' => $plan->precio_linea_extra,
            'lineas_extra_contratadas' => (int) $this->lineas_extra_contratadas,
            'renovacion_automatica' => $this->renovacion_automatica,
        ]);

        $this->mensaje = "Suscripción «{$plan->nombre}» asignada a «{$distribuidora->nombre_comercial}».";
        $this->cancelarSuscripcion();
    }
};
?>

<div>
    <div class="flex items-center justify-between mb-6">
        <div>
            <h2 class="text-2xl font-bold text-slate-900">Distribuidoras</h2>
            <p class="text-sm text-slate-500">Solicitudes, altas y estado de las tiendas</p>
        </div>
        @if (!$mostrandoFormularioCrear)
            <button type="button" wire:click="abrirFormularioCrear"
                class="rounded-lg bg-[#2563EB] text-white text-sm font-medium px-4 py-2 hover:bg-blue-700">
                + Nueva distribuidora
            </button>
        @endif
    </div>

    @if ($mensaje)
        <div class="mb-4 rounded-lg bg-green-50 text-green-700 text-sm p-3">
            {{ $mensaje }}
        </div>
    @endif

    @if ($mostrandoFormularioCrear)
        <form wire:submit="crearDistribuidora"
            class="mb-6 bg-white rounded-xl border border-slate-200 p-5 space-y-4 max-w-3xl">
            <h3 class="font-semibold text-slate-800">Nueva distribuidora</h3>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="md:col-span-2">
                    <label class="block text-sm font-medium mb-1">Nombre comercial *</label>
                    <input type="text" wire:model.live="nuevo_nombre_comercial"
                        class="w-full rounded-lg border-slate-300 text-sm">
                    @error('nuevo_nombre_comercial')
                        <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
                    @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1">Razón social</label>
                    <input type="text" wire:model="nuevo_razon_social"
                        class="w-full rounded-lg border-slate-300 text-sm">
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1">RFC</label>
                    <input type="text" wire:model="nuevo_rfc" class="w-full rounded-lg border-slate-300 text-sm">
                    @error('nuevo_rfc')
                        <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
                    @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1">Slug * (URL interna)</label>
                    <input type="text" wire:model="nuevo_slug" class="w-full rounded-lg border-slate-300 text-sm">
                    @error('nuevo_slug')
                        <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
                    @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1">Subdominio</label>
                    <input type="text" wire:model="nuevo_subdominio"
                        class="w-full rounded-lg border-slate-300 text-sm" placeholder="calzados-ejemplo">
                    @error('nuevo_subdominio')
                        <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
                    @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1">Email público</label>
                    <input type="email" wire:model="nuevo_email_publico"
                        class="w-full rounded-lg border-slate-300 text-sm">
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1">Teléfono público</label>
                    <input type="text" wire:model="nuevo_telefono_publico"
                        class="w-full rounded-lg border-slate-300 text-sm">
                </div>
                <div class="md:col-span-2">
                    <label class="block text-sm font-medium mb-1">Dirección pública</label>
                    <input type="text" wire:model="nuevo_direccion_publica"
                        class="w-full rounded-lg border-slate-300 text-sm">
                </div>
                <div class="md:col-span-2">
                    <label class="block text-sm font-medium mb-1">Descripción pública</label>
                    <textarea wire:model="nuevo_descripcion_publica" rows="2" class="w-full rounded-lg border-slate-300 text-sm"></textarea>
                </div>
                <div class="md:col-span-2">
                    <label class="block text-sm font-medium mb-1">Horario público</label>
                    <input type="text" wire:model="nuevo_horario_publico"
                        class="w-full rounded-lg border-slate-300 text-sm" placeholder="Lunes a sábado 9:00–19:00">
                </div>
            </div>

            <div class="flex flex-wrap gap-4 text-sm">
                <label class="flex items-center gap-2">
                    <input type="checkbox" wire:model="nuevo_activar_ya" class="rounded border-slate-300">
                    Activar de inmediato (si no, queda pendiente)
                </label>
                <label class="flex items-center gap-2">
                    <input type="checkbox" wire:model="nuevo_marketplace_visible" class="rounded border-slate-300">
                    Visible en marketplace
                </label>
            </div>

            <div class="border-t pt-4">
                <h4 class="text-sm font-semibold text-slate-700 mb-2">Administrador de la tienda</h4>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div>
                        <label class="block text-sm font-medium mb-1">Nombre *</label>
                        <input type="text" wire:model="admin_nombre"
                            class="w-full rounded-lg border-slate-300 text-sm">
                        @error('admin_nombre')
                            <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
                        @enderror
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-1">Email login *</label>
                        <input type="email" wire:model="admin_email"
                            class="w-full rounded-lg border-slate-300 text-sm">
                        @error('admin_email')
                            <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
                        @enderror
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-1">Contraseña * (mínimo 8)</label>
                        <input type="password" wire:model="admin_password"
                            class="w-full rounded-lg border-slate-300 text-sm">
                        @error('admin_password')
                            <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
                        @enderror
                    </div>
                </div>
            </div>

            <div class="flex gap-2">
                <button type="submit" class="rounded-lg bg-[#2563EB] text-white text-sm font-medium px-4 py-2">
                    Crear distribuidora
                </button>
                <button type="button" wire:click="cancelarCrear" class="text-sm text-slate-600 px-4 py-2">
                    Cancelar
                </button>
            </div>
        </form>
    @endif

    @if ($mostrarSuscripcion)
        <div class="mb-6 bg-white rounded-xl border border-slate-200 p-5">
            <h3 class="font-semibold mb-1">Asignar plan</h3>
            <p class="text-sm text-slate-500 mb-4">{{ $distribuidoraSuscripcionNombre }}</p>

            <form wire:submit="guardarSuscripcion" class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium mb-1">Plan</label>
                    <select wire:model="plan_id" class="w-full rounded-lg border-slate-300">
                        <option value="">Selecciona un plan</option>
                        @foreach ($this->planesActivos as $plan)
                            <option value="{{ $plan->id }}">
                                {{ $plan->nombre }} — ${{ number_format($plan->precio_base_mensual, 2) }}
                                ({{ $plan->lineas_incluidas }} líneas)
                            </option>
                        @endforeach
                    </select>
                    @error('plan_id')
                        <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label class="block text-sm font-medium mb-1">Líneas extra</label>
                    <input type="number" min="0" wire:model="lineas_extra_contratadas"
                        class="w-full rounded-lg border-slate-300">
                    @error('lineas_extra_contratadas')
                        <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label class="block text-sm font-medium mb-1">Meses</label>
                    <input type="number" min="1" max="24" wire:model="meses"
                        class="w-full rounded-lg border-slate-300">
                    @error('meses')
                        <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
                    @enderror
                </div>

                <div class="flex items-center gap-2 pt-6">
                    <input type="checkbox" wire:model="renovacion_automatica"
                        class="rounded border-slate-300 text-blue-600">
                    <span class="text-sm">Renovación automática</span>
                </div>

                <div class="md:col-span-2 flex gap-2">
                    <button type="submit" class="rounded-lg bg-[#111E38] text-white text-sm px-4 py-2">
                        Asignar
                    </button>
                    <button type="button" wire:click="cancelarSuscripcion"
                        class="rounded-lg border border-slate-200 text-sm px-4 py-2">
                        Cancelar
                    </button>
                </div>
            </form>
        </div>
    @endif

    {{-- TG-195: revisar los datos antes de aprobar o rechazar --}}
    @php
        $detalle = $this->detalle;
        $fecha = fn (?string $iso) => $iso ? \Illuminate\Support\Carbon::parse($iso)->format('d/m/Y H:i') : null;
    @endphp
    @if ($detalle)
        @php
            $camposDetalle = [
                'Razón social' => $detalle['razon_social'],
                'RFC' => $detalle['rfc'],
                'Slug' => $detalle['slug'],
                'Subdominio' => $detalle['subdominio'],
                'Email público' => $detalle['email_publico'],
                'Teléfono público' => $detalle['telefono_publico'],
                'Dirección pública' => $detalle['direccion_publica'],
                'Horario público' => $detalle['horario_publico'],
                'Fecha de solicitud' => $fecha($detalle['fecha_solicitud']),
                'Fecha de aprobación' => $fecha($detalle['fecha_aprobacion']),
            ];
        @endphp
        <div class="mb-6 bg-white rounded-xl border border-slate-200 p-5">
            <div class="flex items-start justify-between gap-4 mb-4">
                <div>
                    <h3 class="font-semibold text-slate-800">{{ $detalle['nombre_comercial'] }}</h3>
                    <p class="text-sm text-slate-500">Estado: {{ ucfirst($detalle['estado']) }}</p>
                </div>
                <button type="button" wire:click="cerrarDatos" class="text-sm text-slate-600 hover:underline">
                    Cerrar
                </button>
            </div>

            <dl class="grid grid-cols-1 md:grid-cols-2 gap-x-6 gap-y-3 text-sm">
                @foreach ($camposDetalle as $etiqueta => $valor)
                    <div>
                        <dt class="text-slate-500">{{ $etiqueta }}</dt>
                        <dd class="text-slate-900">{{ $valor ?: '—' }}</dd>
                    </div>
                @endforeach
                <div class="md:col-span-2">
                    <dt class="text-slate-500">Descripción pública</dt>
                    <dd class="text-slate-900 whitespace-pre-line">{{ $detalle['descripcion_publica'] ?: '—' }}</dd>
                </div>
                <div class="md:col-span-2">
                    <dt class="text-slate-500">Administrador</dt>
                    <dd class="text-slate-900">
                        @forelse ($detalle['administradores'] as $admin)
                            <div>{{ $admin['nombre'] }} · {{ $admin['email'] }}</div>
                        @empty
                            Sin administrador registrado
                        @endforelse
                    </dd>
                </div>
                @if ($detalle['estado'] === 'rechazada')
                    <div class="md:col-span-2">
                        <dt class="text-slate-500">Motivo del rechazo</dt>
                        <dd class="text-slate-900">{{ $detalle['motivo_rechazo'] ?: '—' }}</dd>
                    </div>
                @endif
            </dl>

            @if ($detalle['estado'] === 'pendiente')
                <div class="mt-5 flex gap-2">
                    <button type="button" wire:click="aprobar({{ $detalle['id'] }})"
                        class="rounded-lg bg-green-600 text-white text-sm px-4 py-2 hover:bg-green-700">
                        Aprobar
                    </button>
                    <button type="button" wire:click="abrirRechazo({{ $detalle['id'] }})"
                        class="rounded-lg border border-red-200 text-red-700 text-sm px-4 py-2 hover:bg-red-50">
                        Rechazar
                    </button>
                </div>
            @endif
        </div>
    @endif

    {{-- TG-195: rechazar con motivo --}}
    @if ($distribuidoraRechazoId)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
            <form wire:submit="rechazar" class="w-full max-w-lg bg-white rounded-xl shadow-lg border border-slate-200 p-6">
                <h3 class="font-semibold text-slate-900">Rechazar distribuidora</h3>
                <p class="text-sm text-slate-500 mt-1">
                    «{{ $distribuidoraRechazoNombre }}» no podrá usar FootwearPoint. El rechazo es definitivo.
                </p>

                <div class="mt-4" x-data="{ largo: {{ mb_strlen($motivo_rechazo) }} }">
                    <label for="motivo_rechazo" class="block text-sm font-medium mb-1">Motivo del rechazo *</label>
                    <textarea id="motivo_rechazo" wire:model="motivo_rechazo" rows="4"
                        maxlength="{{ \App\Services\Distribuidora\RechazarDistribuidoraAction::LARGO_MAXIMO_MOTIVO }}"
                        x-on:input="largo = $el.value.length"
                        class="w-full rounded-lg border-slate-300 text-sm"
                        placeholder="Por ejemplo: el RFC no coincide con la razón social."></textarea>
                    <div class="flex justify-between mt-1">
                        <div>
                            @error('motivo_rechazo')
                                <p class="text-xs text-red-600">{{ $message }}</p>
                            @enderror
                        </div>
                        <p class="text-xs text-slate-500">
                            <span x-text="largo">{{ mb_strlen($motivo_rechazo) }}</span>/{{ \App\Services\Distribuidora\RechazarDistribuidoraAction::LARGO_MAXIMO_MOTIVO }}
                        </p>
                    </div>
                </div>

                <div class="mt-5 flex justify-end gap-2">
                    <button type="button" wire:click="cancelarRechazo"
                        class="rounded-lg border border-slate-200 text-sm px-4 py-2">
                        Cancelar
                    </button>
                    <button type="submit" class="rounded-lg bg-red-600 text-white text-sm font-medium px-4 py-2 hover:bg-red-700">
                        Rechazar
                    </button>
                </div>
            </form>
        </div>
    @endif

    <div class="mb-4 flex gap-2 flex-wrap">
        <button wire:click="$set('filtroEstado', '')"
            class="px-3 py-1.5 rounded-lg text-sm {{ $filtroEstado === '' ? 'bg-[#111E38] text-white' : 'bg-white border border-slate-200' }}">
            Todas
        </button>
        @foreach (['pendiente', 'activa', 'suspendida', 'rechazada'] as $estado)
            <button wire:click="$set('filtroEstado', '{{ $estado }}')"
                class="px-3 py-1.5 rounded-lg text-sm {{ $filtroEstado === $estado ? 'bg-[#111E38] text-white' : 'bg-white border border-slate-200' }}">
                {{ ucfirst($estado) }}
            </button>
        @endforeach
    </div>

    <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
        <table class="w-full text-sm">
            <thead class="bg-slate-50 text-slate-600">
                <tr>
                    <th class="text-left px-4 py-3 font-medium">ID</th>
                    <th class="text-left px-4 py-3 font-medium">Nombre</th>
                    <th class="text-left px-4 py-3 font-medium">Estado</th>
                    <th class="text-left px-4 py-3 font-medium">Marketplace</th>
                    <th class="text-left px-4 py-3 font-medium">Acciones</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($this->distribuidoras as $d)
                    <tr>
                        <td class="px-4 py-3">{{ $d->id }}</td>
                        <td class="px-4 py-3">
                            <div class="font-medium">{{ $d->nombre_comercial }}</div>
                            <div class="text-xs text-slate-400">{{ $d->slug }}</div>
                        </td>
                        <td class="px-4 py-3">
                            <span
                                class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium
                                {{ $d->estado === 'activa' ? 'bg-green-100 text-green-700' : '' }}
                                {{ $d->estado === 'pendiente' ? 'bg-amber-100 text-amber-700' : '' }}
                                {{ $d->estado === 'suspendida' ? 'bg-red-100 text-red-700' : '' }}
                                {{ $d->estado === 'rechazada' ? 'bg-slate-100 text-slate-600' : '' }}
                            ">
                                {{ ucfirst($d->estado) }}
                            </span>
                            @if ($d->estado === 'rechazada' && $d->motivo_rechazo)
                                <div class="text-xs text-slate-500 mt-1 max-w-xs">Motivo: {{ $d->motivo_rechazo }}</div>
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            <button wire:click="toggleMarketplace({{ $d->id }})"
                                class="text-xs {{ $d->marketplace_visible ? 'text-green-600' : 'text-slate-400' }}">
                                {{ $d->marketplace_visible ? 'Visible' : 'Oculta' }}
                            </button>
                        </td>
                        <td class="px-4 py-3 space-x-2">
                            <button wire:click="verDatos({{ $d->id }})"
                                class="text-xs text-slate-700 hover:underline">Ver datos</button>
                            @if ($d->estado === 'pendiente')
                                <button wire:click="aprobar({{ $d->id }})"
                                    class="text-xs text-green-700 hover:underline">Aprobar</button>
                                <button wire:click="abrirRechazo({{ $d->id }})"
                                    class="text-xs text-red-700 hover:underline">Rechazar</button>
                            @endif
                            @if ($d->estado === 'activa')
                                <button wire:click="suspender({{ $d->id }})"
                                    wire:confirm="Su personal, revendedores y clientes no podrán usar FootwearPoint hasta reactivarla. Su información se conserva. ¿Continuar?"
                                    class="text-xs text-red-600 hover:underline">Suspender</button>
                            @endif
                            @if ($d->estado === 'suspendida')
                                <button wire:click="reactivar({{ $d->id }})"
                                    class="text-xs text-blue-700 hover:underline">Reactivar</button>
                            @endif
                            @if (in_array($d->estado, ['activa', 'suspendida']))
                                @php
                                    $tienePlan = $d->suscripciones->where('estado', 'activa')->isNotEmpty();
                                @endphp
                                <button wire:click="abrirSuscripcion({{ $d->id }})"
                                    class="text-xs text-indigo-700 hover:underline">
                                    @if ($tienePlan)
                                        Cambiar plan
                                    @else
                                        Asignar plan
                                    @endif
                                </button>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-4 py-8 text-center text-slate-400">
                            No hay distribuidoras con este filtro.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
