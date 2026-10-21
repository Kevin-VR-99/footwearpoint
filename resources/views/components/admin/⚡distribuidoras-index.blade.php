<?php

use App\Exceptions\OperacionInvalidaException;
use App\Models\Distribuidora;
use App\Models\PlanSuscripcion;
use App\Rules\SubdominioValido;
use App\Services\Distribuidora\AprobacionDistribuidoraException;
use App\Services\Distribuidora\ActualizarAdminDistribuidoraAction;
use App\Services\Distribuidora\ActualizarDistribuidoraAction;
use App\Services\Distribuidora\AprobarDistribuidoraAction;
use App\Services\Distribuidora\CambiarVisibilidadMarketplaceAction;
use App\Services\Distribuidora\CambioEstadoDistribuidora;
use App\Services\Distribuidora\CrearDistribuidoraAction;
use App\Services\Distribuidora\DatosSolicitudDistribuidoraAction;
use App\Services\Distribuidora\ReactivarDistribuidoraAction;
use App\Services\Distribuidora\RechazarDistribuidoraAction;
use App\Services\Distribuidora\SuspenderDistribuidoraAction;
use App\Services\Suscripcion\AsignarSuscripcionAction;
use App\Support\MensajeError;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Layout('layouts.admin')] #[Title('Distribuidoras — Admin')] class extends Component {
    /** Los únicos estados por los que se puede filtrar (enum de la tabla). */
    public const ESTADOS_FILTRABLES = ['pendiente', 'activa', 'suspendida', 'rechazada'];

    /**
     * TG-220: va en la dirección (?estado=pendiente) para poder enlazar aquí
     * desde el correo que avisa de una solicitud nueva. Un valor que no sea
     * un estado válido se ignora y queda el filtro de siempre: todas.
     */
    #[Url(as: 'estado', except: '')]
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

    // Editar distribuidora y su administrador (TG-194)
    public ?int $distribuidoraEdicionId = null;
    public string $distribuidoraEdicionNombre = '';
    public string $editar_nombre_comercial = '';
    public string $editar_razon_social = '';
    public string $editar_rfc = '';
    public string $editar_slug = '';
    public string $editar_subdominio = '';
    public string $editar_email_publico = '';
    public string $editar_telefono_publico = '';
    public string $editar_direccion_publica = '';
    public string $editar_descripcion_publica = '';
    public string $editar_horario_publico = '';
    public bool $editarTieneAdmin = false;
    public string $editar_admin_nombre = '';
    public string $editar_admin_email = '';
    public string $editar_admin_password = '';
    public string $editar_admin_password_confirmation = '';

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
        // TG-232: el subdominio es un nombre DNS, siempre en minúsculas.
        $this->nuevo_subdominio = strtolower(trim($this->nuevo_subdominio));

        $this->validate([
            'nuevo_nombre_comercial' => ['required', 'string', 'max:150'],
            'nuevo_razon_social' => ['nullable', 'string', 'max:200'],
            // RFC mexicano: 12 caracteres persona moral, 13 persona física.
            'nuevo_rfc' => ['nullable', 'string', 'regex:/^[A-ZÑ&]{3,4}\d{6}[A-Z0-9]{3}$/u', Rule::unique('distribuidoras', 'rfc')],
            'nuevo_slug' => ['required', 'string', 'max:120', 'unique:distribuidoras,slug', 'alpha_dash'],
            'nuevo_subdominio' => ['nullable', 'string', new SubdominioValido(), Rule::unique('distribuidoras', 'subdominio')],
            'nuevo_email_publico' => ['nullable', 'email', 'max:190'],
            'nuevo_telefono_publico' => ['nullable', 'string', 'max:30'],
            'nuevo_direccion_publica' => ['nullable', 'string', 'max:300'],
            'nuevo_descripcion_publica' => ['nullable', 'string', 'max:2000'],
            'nuevo_horario_publico' => ['nullable', 'string', 'max:300'],
            // TG-194: el administrador de la tienda es obligatorio.
            'admin_nombre' => ['required', 'string', 'max:150'],
            'admin_email' => ['required', 'email', 'max:190', 'unique:usuarios,email'],
            'admin_password' => ['required', 'string', 'min:8', 'max:255'],
        ], [
            'admin_password.max' => 'La contraseña no puede tener más de 255 caracteres.',
            'nuevo_descripcion_publica.max' => 'La descripción no puede tener más de 2000 caracteres.',
            'nuevo_nombre_comercial.required' => 'El nombre comercial es obligatorio.',
            'nuevo_nombre_comercial.max' => 'El nombre comercial no puede pasar de 150 caracteres.',
            'nuevo_razon_social.max' => 'La razón social no puede pasar de 200 caracteres.',
            'nuevo_rfc.regex' => 'El RFC no tiene un formato válido (12 caracteres persona moral o 13 persona física).',
            'nuevo_rfc.unique' => 'Ese RFC ya está registrado en otra distribuidora.',
            'nuevo_slug.required' => 'El slug es obligatorio.',
            'nuevo_slug.max' => 'El slug no puede pasar de 120 caracteres.',
            'nuevo_slug.unique' => 'Ese slug ya lo usa otra distribuidora.',
            'nuevo_slug.alpha_dash' => 'El slug solo puede tener letras, números, guiones y guiones bajos.',
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
                // TG-197: sus categorías activas, para mostrarlas en la tabla.
                'categoriasDirectorio' => fn ($q) => $q->activas()->orderBy('nombre'),
            ])
            ->orderByDesc('id');

        // TG-220: el filtro puede venir de la dirección, así que solo se usa
        // si es un estado de verdad; cualquier otra cosa se ignora.
        if (in_array($this->filtroEstado, self::ESTADOS_FILTRABLES, true)) {
            $query->where('estado', $this->filtroEstado);
        }

        return $query->get();
    }

    /** Cuántas hay por estado, para las tarjetas de métricas. */
    public function getResumenProperty(): array
    {
        $porEstado = Distribuidora::query()
            ->selectRaw('estado, COUNT(*) as total')
            ->groupBy('estado')
            ->pluck('total', 'estado');

        return [
            'total' => (int) $porEstado->sum(),
            'pendiente' => (int) ($porEstado['pendiente'] ?? 0),
            'activa' => (int) ($porEstado['activa'] ?? 0),
            'suspendida' => (int) ($porEstado['suspendida'] ?? 0),
            'visibles' => (int) Distribuidora::query()->where('estado', 'activa')->where('marketplace_visible', true)->count(),
        ];
    }

    /** Los mismos que acepta el filtro, para pintar los botones. */
    public function getEstadosFiltrablesProperty(): array
    {
        return self::ESTADOS_FILTRABLES;
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
        $distribuidora = Distribuidora::findOrFail($id);

        $this->distribuidoraDetalleId = $distribuidora->id;
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

    /** Solo el admin general edita distribuidoras (también en cada acción, no solo al montar). */
    private function exigirAdminGeneral(): void
    {
        setPermissionsTeamId(0);

        if (! Auth::check() || ! Auth::user()->hasRole('admin_general')) {
            abort(403, 'Solo admin general.');
        }
    }

    /** TG-194: abre el formulario para editar la distribuidora y su administrador. */
    public function abrirEdicion(int $id): void
    {
        $this->exigirAdminGeneral();

        $distribuidora = Distribuidora::findOrFail($id);
        $admin = app(ActualizarAdminDistribuidoraAction::class)->administradorDe($distribuidora);

        $this->resetValidation();
        $this->mensaje = '';
        $this->mostrarSuscripcion = false;
        $this->mostrandoFormularioCrear = false;

        $this->distribuidoraEdicionId = $distribuidora->id;
        $this->distribuidoraEdicionNombre = $distribuidora->nombre_comercial;
        $this->editar_nombre_comercial = (string) $distribuidora->nombre_comercial;
        $this->editar_razon_social = (string) $distribuidora->razon_social;
        $this->editar_rfc = (string) $distribuidora->rfc;
        $this->editar_slug = (string) $distribuidora->slug;
        $this->editar_subdominio = (string) $distribuidora->subdominio;
        $this->editar_email_publico = (string) $distribuidora->email_publico;
        $this->editar_telefono_publico = (string) $distribuidora->telefono_publico;
        $this->editar_direccion_publica = (string) $distribuidora->direccion_publica;
        $this->editar_descripcion_publica = (string) $distribuidora->descripcion_publica;
        $this->editar_horario_publico = (string) $distribuidora->horario_publico;

        $this->editarTieneAdmin = $admin !== null;
        $this->editar_admin_nombre = (string) $admin?->nombre;
        $this->editar_admin_email = (string) $admin?->email;
        $this->editar_admin_password = '';
        $this->editar_admin_password_confirmation = '';
    }

    public function cancelarEdicion(): void
    {
        $this->resetValidation();
        $this->distribuidoraEdicionId = null;
        $this->distribuidoraEdicionNombre = '';
        $this->editar_admin_password = '';
        $this->editar_admin_password_confirmation = '';
    }

    /** TG-194: guarda los datos de la distribuidora y, si tiene, de su administrador. */
    public function guardarEdicion(): void
    {
        $this->exigirAdminGeneral();
        $this->mensaje = '';
        $this->resetValidation();

        if ($this->distribuidoraEdicionId === null) {
            return;
        }

        try {
            $distribuidora = Distribuidora::findOrFail($this->distribuidoraEdicionId);

            $distribuidora = app(ActualizarDistribuidoraAction::class)->ejecutar($distribuidora, [
                'nombre_comercial' => $this->editar_nombre_comercial,
                'razon_social' => $this->editar_razon_social,
                'rfc' => $this->editar_rfc,
                'slug' => $this->editar_slug,
                'subdominio' => $this->editar_subdominio,
                'email_publico' => $this->editar_email_publico,
                'telefono_publico' => $this->editar_telefono_publico,
                'direccion_publica' => $this->editar_direccion_publica,
                'descripcion_publica' => $this->editar_descripcion_publica,
                'horario_publico' => $this->editar_horario_publico,
            ]);
        } catch (ValidationException $e) {
            $this->erroresAlCampo($e, 'editar_', 'editar_nombre_comercial');
            return;
        } catch (\Throwable $e) {
            // TG-224 (G3): el detalle técnico va al log, nunca a la pantalla.
            $this->addError('editar_nombre_comercial', MensajeError::paraUsuario($e, 'No se pudo guardar la distribuidora. Intenta de nuevo.'));
            return;
        }

        if ($this->editarTieneAdmin) {
            try {
                app(ActualizarAdminDistribuidoraAction::class)->ejecutar($distribuidora, [
                    'nombre' => $this->editar_admin_nombre,
                    'email' => $this->editar_admin_email,
                    'password' => $this->editar_admin_password,
                    'password_confirmation' => $this->editar_admin_password_confirmation,
                ]);
            } catch (ValidationException $e) {
                $this->editar_admin_password = '';
                $this->editar_admin_password_confirmation = '';
                $this->erroresAlCampo($e, 'editar_admin_', 'editar_admin_email');
                $this->mensaje = 'Los datos de la distribuidora se guardaron; revisa los de su administrador.';
                return;
            } catch (\Throwable $e) {
                $this->editar_admin_password = '';
                $this->editar_admin_password_confirmation = '';
                $this->addError('editar_admin_email', MensajeError::paraUsuario($e, 'No se pudo guardar el administrador. Intenta de nuevo.'));
                return;
            }
        }

        $this->cancelarEdicion();
        $this->mensaje = "Distribuidora «{$distribuidora->nombre_comercial}» actualizada.";
    }

    /** Pone cada error de la acción en su campo del formulario (con prefijo). */
    private function erroresAlCampo(ValidationException $e, string $prefijo, string $porDefecto): void
    {
        foreach ($e->errors() as $campo => $mensajes) {
            $propiedad = $prefijo . $campo;
            $this->addError(property_exists($this, $propiedad) ? $propiedad : $porDefecto, $mensajes[0]);
        }
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

    /** Mostrar u ocultar en el marketplace (E2-05). Misma regla que la API (TG-197). */
    public function toggleMarketplace(int $id)
    {
        $d = Distribuidora::findOrFail($id);

        try {
            $d = app(CambiarVisibilidadMarketplaceAction::class)->ejecutar($d, ! $d->marketplace_visible);
        } catch (OperacionInvalidaException $e) {
            $this->mensaje = $e->getMessage();
            return;
        } catch (\Throwable $e) {
            $this->mensaje = MensajeError::paraUsuario($e, 'No se pudo cambiar la visibilidad en el marketplace. Intenta de nuevo.');
            return;
        }

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

        // TG-224 (G3): misma acción que usa la API (antes estaba copiada aquí).
        try {
            app(AsignarSuscripcionAction::class)->ejecutar(
                $distribuidora,
                $plan,
                (int) $this->meses,
                (int) $this->lineas_extra_contratadas,
                (bool) $this->renovacion_automatica,
            );
        } catch (OperacionInvalidaException $e) {
            $this->mensaje = $e->getMessage();
            return;
        }

        $this->mensaje = "Suscripción «{$plan->nombre}» asignada a «{$distribuidora->nombre_comercial}».";
        $this->cancelarSuscripcion();
    }
};
?>

<div>
    <x-panel.encabezado titulo="Distribuidoras" class="mb-6">
            <p class="mt-1 text-sm text-fp-text-muted">Solicitudes, altas y estado de las tiendas</p>
        <x-slot:acciones>
        @if (!$mostrandoFormularioCrear)
            <button type="button" wire:click="abrirFormularioCrear"
                class="fp-btn fp-btn-primario">
                + Nueva distribuidora
            </button>
        @endif
        </x-slot:acciones>
    </x-panel.encabezado>

    @php
        $resumen = $this->resumen;
    @endphp
    <div class="fp-escalonado mb-6 grid grid-cols-2 gap-3 sm:gap-4 xl:grid-cols-4" data-metricas-admin>
        <x-panel.metrica titulo="Distribuidoras" :valor="$resumen['total']" detalle="Registradas en FootwearPoint"
            icono="M3 21h18M5 21V7l7-4 7 4v14M9 21v-6h6v6" :progreso="100" />
        <x-panel.metrica titulo="Solicitudes pendientes" :valor="$resumen['pendiente']" tono="aviso" detalle="Esperan aprobación o rechazo"
            icono="M12 8v4l3 2m6-2a9 9 0 11-18 0 9 9 0 0118 0z" :progreso="$resumen['total'] ? $resumen['pendiente'] / $resumen['total'] * 100 : 0" />
        <x-panel.metrica titulo="Activas" :valor="$resumen['activa']" tono="exito" :detalle="$resumen['visibles'].' visibles en el marketplace'"
            icono="M5 13l4 4L19 7" :progreso="$resumen['total'] ? $resumen['activa'] / $resumen['total'] * 100 : 0" />
        <x-panel.metrica titulo="Suspendidas" :valor="$resumen['suspendida']" tono="peligro" detalle="Sin acceso hasta reactivarlas"
            icono="M18.364 5.636l-12.728 12.728M5.636 5.636a9 9 0 1012.728 12.728A9 9 0 005.636 5.636z" :progreso="$resumen['total'] ? $resumen['suspendida'] / $resumen['total'] * 100 : 0" />
    </div>

    @if ($mensaje)
        <x-panel.alerta tipo="exito" class="mb-4">
            {{ $mensaje }}
        </x-panel.alerta>
    @endif

    @if ($mostrandoFormularioCrear)
        <form wire:submit="crearDistribuidora"
            class="fp-formulario fp-aparecer mb-6 p-5 space-y-4 max-w-3xl">
            <h3 class="text-lg font-semibold text-fp-sidebar">Nueva distribuidora</h3>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="fp-campo md:col-span-2">
                    <label for="distribuidoras-index-nuevo-nombre-comercial" class="block text-sm font-medium mb-1 text-slate-700">Nombre comercial *</label>
                    <input id="distribuidoras-index-nuevo-nombre-comercial" type="text" wire:model.live="nuevo_nombre_comercial"
                        class="w-full rounded-lg text-sm">
                    @error('nuevo_nombre_comercial')
                        <p class="text-xs text-fp-danger mt-1">{{ $message }}</p>
                    @enderror
                </div>
                <div class="fp-campo">
                    <label for="distribuidoras-index-nuevo-razon-social" class="block text-sm font-medium mb-1 text-slate-700">Razón social</label>
                    <input id="distribuidoras-index-nuevo-razon-social" type="text" wire:model="nuevo_razon_social"
                        class="w-full rounded-lg text-sm">
                </div>
                <div class="fp-campo">
                    <label for="distribuidoras-index-nuevo-rfc" class="block text-sm font-medium mb-1 text-slate-700">RFC</label>
                    <input id="distribuidoras-index-nuevo-rfc" type="text" wire:model="nuevo_rfc" class="w-full rounded-lg text-sm">
                    @error('nuevo_rfc')
                        <p class="text-xs text-fp-danger mt-1">{{ $message }}</p>
                    @enderror
                </div>
                <div class="fp-campo">
                    <label for="distribuidoras-index-nuevo-slug" class="block text-sm font-medium mb-1 text-slate-700">Slug * (URL interna)</label>
                    <input id="distribuidoras-index-nuevo-slug" type="text" wire:model="nuevo_slug" class="w-full rounded-lg text-sm">
                    @error('nuevo_slug')
                        <p class="text-xs text-fp-danger mt-1">{{ $message }}</p>
                    @enderror
                </div>
                <div class="fp-campo">
                    <label for="distribuidoras-index-nuevo-subdominio" class="block text-sm font-medium mb-1 text-slate-700">Subdominio</label>
                    <input id="distribuidoras-index-nuevo-subdominio" type="text" wire:model="nuevo_subdominio"
                        class="w-full rounded-lg text-sm" placeholder="calzados-ejemplo">
                    @error('nuevo_subdominio')
                        <p class="text-xs text-fp-danger mt-1">{{ $message }}</p>
                    @enderror
                </div>
                <div class="fp-campo">
                    <label for="distribuidoras-index-nuevo-email-publico" class="block text-sm font-medium mb-1 text-slate-700">Email público</label>
                    <input id="distribuidoras-index-nuevo-email-publico" type="email" wire:model="nuevo_email_publico"
                        class="w-full rounded-lg text-sm">
                </div>
                <div class="fp-campo">
                    <label for="distribuidoras-index-nuevo-telefono-publico" class="block text-sm font-medium mb-1 text-slate-700">Teléfono público</label>
                    <input id="distribuidoras-index-nuevo-telefono-publico" type="text" wire:model="nuevo_telefono_publico"
                        class="w-full rounded-lg text-sm">
                </div>
                <div class="fp-campo md:col-span-2">
                    <label for="distribuidoras-index-nuevo-direccion-publica" class="block text-sm font-medium mb-1 text-slate-700">Dirección pública</label>
                    <input id="distribuidoras-index-nuevo-direccion-publica" type="text" wire:model="nuevo_direccion_publica"
                        class="w-full rounded-lg text-sm">
                </div>
                <div class="fp-campo md:col-span-2">
                    <label for="distribuidoras-index-nuevo-descripcion-publica" class="block text-sm font-medium mb-1 text-slate-700">Descripción pública</label>
                    <textarea id="distribuidoras-index-nuevo-descripcion-publica" wire:model="nuevo_descripcion_publica" rows="2" class="w-full rounded-lg text-sm"></textarea>
                </div>
                <div class="fp-campo md:col-span-2">
                    <label for="distribuidoras-index-nuevo-horario-publico" class="block text-sm font-medium mb-1 text-slate-700">Horario público</label>
                    <input id="distribuidoras-index-nuevo-horario-publico" type="text" wire:model="nuevo_horario_publico"
                        class="w-full rounded-lg text-sm" placeholder="Lunes a sábado 9:00–19:00">
                </div>
            </div>

            <div class="flex flex-wrap gap-4 text-sm">
                <label class="flex items-center gap-2">
                    <input type="checkbox" wire:model="nuevo_activar_ya" class="rounded">
                    Activar de inmediato (si no, queda pendiente)
                </label>
                <label class="flex items-center gap-2">
                    <input type="checkbox" wire:model="nuevo_marketplace_visible" class="rounded">
                    Visible en marketplace
                </label>
            </div>

            <div class="border-t pt-4">
                <h4 class="text-sm font-semibold text-slate-700 mb-2">Administrador de la tienda</h4>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div class="fp-campo">
                        <label for="distribuidoras-index-admin-nombre" class="block text-sm font-medium mb-1 text-slate-700">Nombre *</label>
                        <input id="distribuidoras-index-admin-nombre" type="text" wire:model="admin_nombre"
                            class="w-full rounded-lg text-sm">
                        @error('admin_nombre')
                            <p class="text-xs text-fp-danger mt-1">{{ $message }}</p>
                        @enderror
                    </div>
                    <div class="fp-campo">
                        <label for="distribuidoras-index-admin-email" class="block text-sm font-medium mb-1 text-slate-700">Email login *</label>
                        <input id="distribuidoras-index-admin-email" type="email" wire:model="admin_email"
                            class="w-full rounded-lg text-sm">
                        @error('admin_email')
                            <p class="text-xs text-fp-danger mt-1">{{ $message }}</p>
                        @enderror
                    </div>
                    <div class="fp-campo">
                        <label for="distribuidoras-index-admin-password" class="block text-sm font-medium mb-1 text-slate-700">Contraseña * (mínimo 8)</label>
                        <input id="distribuidoras-index-admin-password" type="password" wire:model="admin_password"
                            class="w-full rounded-lg text-sm">
                        @error('admin_password')
                            <p class="text-xs text-fp-danger mt-1">{{ $message }}</p>
                        @enderror
                    </div>
                </div>
            </div>

            <div class="flex gap-2">
                <button type="submit" class="fp-btn fp-btn-primario">
                    Crear distribuidora
                </button>
                <button type="button" wire:click="cancelarCrear" class="fp-btn fp-btn-fantasma">
                    Cancelar
                </button>
            </div>
        </form>
    @endif

    @if ($mostrarSuscripcion)
        <div class="fp-formulario fp-aparecer mb-6 p-5">
            <h3 class="mb-1 text-lg font-semibold text-fp-sidebar">Asignar plan</h3>
            <p class="text-sm text-slate-500 mb-4">{{ $distribuidoraSuscripcionNombre }}</p>

            <form wire:submit="guardarSuscripcion" class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="fp-campo">
                    <label for="distribuidoras-index-plan-id" class="block text-sm font-medium mb-1 text-slate-700">Plan</label>
                    <select id="distribuidoras-index-plan-id" wire:model="plan_id" class="w-full rounded-lg">
                        <option value="">Selecciona un plan</option>
                        @foreach ($this->planesActivos as $plan)
                            <option value="{{ $plan->id }}">
                                {{ $plan->nombre }} — ${{ number_format($plan->precio_base_mensual, 2) }}
                                ({{ $plan->lineas_incluidas }} líneas)
                            </option>
                        @endforeach
                    </select>
                    @error('plan_id')
                        <p class="text-xs text-fp-danger mt-1">{{ $message }}</p>
                    @enderror
                </div>

                <div class="fp-campo">
                    <label for="distribuidoras-index-lineas-extra-contratadas" class="block text-sm font-medium mb-1 text-slate-700">Líneas extra</label>
                    <input id="distribuidoras-index-lineas-extra-contratadas" type="number" min="0" wire:model="lineas_extra_contratadas"
                        class="w-full rounded-lg">
                    @error('lineas_extra_contratadas')
                        <p class="text-xs text-fp-danger mt-1">{{ $message }}</p>
                    @enderror
                </div>

                <div class="fp-campo">
                    <label for="distribuidoras-index-meses" class="block text-sm font-medium mb-1 text-slate-700">Meses</label>
                    <input id="distribuidoras-index-meses" type="number" min="1" max="24" wire:model="meses"
                        class="w-full rounded-lg">
                    @error('meses')
                        <p class="text-xs text-fp-danger mt-1">{{ $message }}</p>
                    @enderror
                </div>

                <div class="flex items-center gap-2 pt-6">
                    <input type="checkbox" wire:model="renovacion_automatica"
                        class="rounded text-fp-primary">
                    <span class="text-sm">Renovación automática</span>
                </div>

                <div class="md:col-span-2 flex gap-2">
                    <button type="submit" class="fp-btn fp-btn-primario">
                        Asignar
                    </button>
                    <button type="button" wire:click="cancelarSuscripcion"
                        class="fp-btn fp-btn-secundario">
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
                // TG-220: el plan que pidió en el formulario público.
                'Plan solicitado' => $detalle['plan_solicitado'],
                'Fecha de solicitud' => $fecha($detalle['fecha_solicitud']),
                'Fecha de aprobación' => $fecha($detalle['fecha_aprobacion']),
            ];
        @endphp
        <div class="fp-tarjeta fp-aparecer mb-6 p-5" data-detalle-distribuidora>
            <div class="flex items-start justify-between gap-4 mb-4">
                <div>
                    <h3 class="font-semibold text-slate-800">{{ $detalle['nombre_comercial'] }}</h3>
                    <p class="mt-1 text-sm text-slate-500">Estado: <x-panel.insignia :variante="['activa' => 'success', 'pendiente' => 'warning', 'suspendida' => 'danger'][$detalle['estado']] ?? 'neutral'" :viva="$detalle['estado'] === 'pendiente'" punto>{{ ucfirst($detalle['estado']) }}</x-panel.insignia></p>
                </div>
                <button type="button" wire:click="cerrarDatos" class="fp-btn fp-btn-fantasma fp-btn-sm">
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
                            <div class="flex flex-wrap items-center gap-2">
                                <span>{{ $admin['nombre'] }} · {{ $admin['email'] }}</span>
                                {{-- TG-220 (A11): avisa si nadie confirmó ese correo. --}}
                                @if ($admin['correo_confirmado'])
                                    <x-panel.insignia variante="success" punto>Correo confirmado</x-panel.insignia>
                                @else
                                    <x-panel.insignia variante="warning" punto>Correo sin confirmar</x-panel.insignia>
                                @endif
                            </div>
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
                        class="fp-btn fp-btn-primario">
                        Aprobar
                    </button>
                    <button type="button" wire:click="abrirRechazo({{ $detalle['id'] }})"
                        class="fp-btn fp-btn-secundario text-fp-danger hover:!text-fp-danger hover:!border-fp-danger/40">
                        Rechazar
                    </button>
                </div>
            @endif
        </div>
    @endif

    {{-- TG-194: editar distribuidora y su administrador --}}
    @if ($distribuidoraEdicionId)
        <x-panel.modal titulo="Editar distribuidora" :subtitulo="'«'.$distribuidoraEdicionNombre.'»'" ancho="max-w-3xl"
            formulario="guardarEdicion" cerrar="cancelarEdicion" class="space-y-4">

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="fp-campo md:col-span-2">
                        <label for="editar_nombre_comercial" class="block text-sm font-medium mb-1 text-slate-700">Nombre comercial *</label>
                        <input id="editar_nombre_comercial" type="text" wire:model="editar_nombre_comercial" class="w-full rounded-lg text-sm">
                        @error('editar_nombre_comercial')
                            <p class="text-xs text-fp-danger mt-1">{{ $message }}</p>
                        @enderror
                    </div>
                    <div class="fp-campo">
                        <label for="editar_razon_social" class="block text-sm font-medium mb-1 text-slate-700">Razón social</label>
                        <input id="editar_razon_social" type="text" wire:model="editar_razon_social" class="w-full rounded-lg text-sm">
                        @error('editar_razon_social')
                            <p class="text-xs text-fp-danger mt-1">{{ $message }}</p>
                        @enderror
                    </div>
                    <div class="fp-campo">
                        <label for="editar_rfc" class="block text-sm font-medium mb-1 text-slate-700">RFC</label>
                        <input id="editar_rfc" type="text" wire:model="editar_rfc" class="w-full rounded-lg text-sm">
                        @error('editar_rfc')
                            <p class="text-xs text-fp-danger mt-1">{{ $message }}</p>
                        @enderror
                    </div>
                    <div class="fp-campo">
                        <label for="editar_slug" class="block text-sm font-medium mb-1 text-slate-700">Slug * (URL interna)</label>
                        <input id="editar_slug" type="text" wire:model="editar_slug" class="w-full rounded-lg text-sm">
                        @error('editar_slug')
                            <p class="text-xs text-fp-danger mt-1">{{ $message }}</p>
                        @enderror
                    </div>
                    <div class="fp-campo">
                        <label for="editar_subdominio" class="block text-sm font-medium mb-1 text-slate-700">Subdominio</label>
                        <input id="editar_subdominio" type="text" wire:model="editar_subdominio" class="w-full rounded-lg text-sm">
                        @error('editar_subdominio')
                            <p class="text-xs text-fp-danger mt-1">{{ $message }}</p>
                        @enderror
                    </div>
                    <div class="fp-campo">
                        <label for="editar_email_publico" class="block text-sm font-medium mb-1 text-slate-700">Email público</label>
                        <input id="editar_email_publico" type="email" wire:model="editar_email_publico" class="w-full rounded-lg text-sm">
                        @error('editar_email_publico')
                            <p class="text-xs text-fp-danger mt-1">{{ $message }}</p>
                        @enderror
                    </div>
                    <div class="fp-campo">
                        <label for="editar_telefono_publico" class="block text-sm font-medium mb-1 text-slate-700">Teléfono público</label>
                        <input id="editar_telefono_publico" type="text" wire:model="editar_telefono_publico" class="w-full rounded-lg text-sm">
                        @error('editar_telefono_publico')
                            <p class="text-xs text-fp-danger mt-1">{{ $message }}</p>
                        @enderror
                    </div>
                    <div class="fp-campo md:col-span-2">
                        <label for="editar_direccion_publica" class="block text-sm font-medium mb-1 text-slate-700">Dirección pública</label>
                        <input id="editar_direccion_publica" type="text" wire:model="editar_direccion_publica" class="w-full rounded-lg text-sm">
                        @error('editar_direccion_publica')
                            <p class="text-xs text-fp-danger mt-1">{{ $message }}</p>
                        @enderror
                    </div>
                    <div class="fp-campo md:col-span-2">
                        <label for="editar_descripcion_publica" class="block text-sm font-medium mb-1 text-slate-700">Descripción pública</label>
                        <textarea id="editar_descripcion_publica" wire:model="editar_descripcion_publica" rows="2" class="w-full rounded-lg text-sm"></textarea>
                        @error('editar_descripcion_publica')
                            <p class="text-xs text-fp-danger mt-1">{{ $message }}</p>
                        @enderror
                    </div>
                    <div class="fp-campo md:col-span-2">
                        <label for="editar_horario_publico" class="block text-sm font-medium mb-1 text-slate-700">Horario público</label>
                        <input id="editar_horario_publico" type="text" wire:model="editar_horario_publico" class="w-full rounded-lg text-sm">
                        @error('editar_horario_publico')
                            <p class="text-xs text-fp-danger mt-1">{{ $message }}</p>
                        @enderror
                    </div>
                </div>

                <div class="border-t border-slate-100 pt-4">
                    <h4 class="font-semibold text-slate-800 text-sm">Administrador de la distribuidora</h4>
                    @if ($editarTieneAdmin)
                        <div class="mt-3 grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div class="fp-campo">
                                <label for="editar_admin_nombre" class="block text-sm font-medium mb-1 text-slate-700">Nombre *</label>
                                <input id="editar_admin_nombre" type="text" wire:model="editar_admin_nombre" class="w-full rounded-lg text-sm">
                                @error('editar_admin_nombre')
                                    <p class="text-xs text-fp-danger mt-1">{{ $message }}</p>
                                @enderror
                            </div>
                            <div class="fp-campo">
                                <label for="editar_admin_email" class="block text-sm font-medium mb-1 text-slate-700">Correo *</label>
                                <input id="editar_admin_email" type="email" wire:model="editar_admin_email" class="w-full rounded-lg text-sm">
                                @error('editar_admin_email')
                                    <p class="text-xs text-fp-danger mt-1">{{ $message }}</p>
                                @enderror
                            </div>
                            <div class="fp-campo">
                                <label for="editar_admin_password" class="block text-sm font-medium mb-1 text-slate-700">Nueva contraseña</label>
                                <input id="editar_admin_password" type="password" wire:model="editar_admin_password" autocomplete="new-password" class="w-full rounded-lg text-sm">
                                @error('editar_admin_password')
                                    <p class="text-xs text-fp-danger mt-1">{{ $message }}</p>
                                @enderror
                            </div>
                            <div class="fp-campo">
                                <label for="editar_admin_password_confirmation" class="block text-sm font-medium mb-1 text-slate-700">Confirmar nueva contraseña</label>
                                <input id="editar_admin_password_confirmation" type="password" wire:model="editar_admin_password_confirmation" autocomplete="new-password" class="w-full rounded-lg text-sm">
                                @error('editar_admin_password_confirmation')
                                    <p class="text-xs text-fp-danger mt-1">{{ $message }}</p>
                                @enderror
                            </div>
                        </div>
                        <p class="text-xs text-slate-500 mt-2">Deja la contraseña vacía para no cambiarla. Si la cambias, mínimo 8 caracteres.</p>
                    @else
                        <x-panel.alerta tipo="aviso" class="mt-3">
                            {{ \App\Services\Distribuidora\ActualizarAdminDistribuidoraAction::MENSAJE_SIN_ADMIN }}
                        </x-panel.alerta>
                    @endif
                </div>

                <div class="flex justify-end gap-2">
                    <button type="button" wire:click="cancelarEdicion"
                        class="fp-btn fp-btn-secundario">
                        Cancelar
                    </button>
                    <button type="submit" class="fp-btn fp-btn-primario">
                        Guardar cambios
                    </button>
                </div>
        </x-panel.modal>
    @endif

    {{-- TG-195: rechazar con motivo --}}
    @if ($distribuidoraRechazoId)
        <x-panel.modal titulo="Rechazar distribuidora"
            :subtitulo="'«'.$distribuidoraRechazoNombre.'» no podrá usar FootwearPoint. El rechazo es definitivo.'"
            formulario="rechazar" cerrar="cancelarRechazo">

                <div class="mt-4" x-data="{ largo: {{ mb_strlen($motivo_rechazo) }} }">
                    <label for="motivo_rechazo" class="block text-sm font-medium mb-1 text-slate-700">Motivo del rechazo *</label>
                    <textarea id="motivo_rechazo" wire:model="motivo_rechazo" rows="4"
                        maxlength="{{ \App\Services\Distribuidora\RechazarDistribuidoraAction::LARGO_MAXIMO_MOTIVO }}"
                        x-on:input="largo = $el.value.length"
                        class="w-full rounded-lg text-sm"
                        placeholder="Por ejemplo: el RFC no coincide con la razón social."></textarea>
                    <div class="flex justify-between mt-1">
                        <div>
                            @error('motivo_rechazo')
                                <p class="text-xs text-fp-danger">{{ $message }}</p>
                            @enderror
                        </div>
                        <p class="text-xs text-slate-500">
                            <span x-text="largo">{{ mb_strlen($motivo_rechazo) }}</span>/{{ \App\Services\Distribuidora\RechazarDistribuidoraAction::LARGO_MAXIMO_MOTIVO }}
                        </p>
                    </div>
                </div>

                <div class="mt-5 flex justify-end gap-2">
                    <button type="button" wire:click="cancelarRechazo"
                        class="fp-btn fp-btn-secundario">
                        Cancelar
                    </button>
                    <button type="submit" class="fp-btn fp-btn-peligro">
                        Rechazar
                    </button>
                </div>
        </x-panel.modal>
    @endif

    <div class="fp-pestanas mb-4" role="tablist" aria-label="Filtrar por estado">
        <button type="button" role="tab" wire:click="$set('filtroEstado', '')" aria-selected="{{ $filtroEstado === '' ? 'true' : 'false' }}"
            class="fp-pestana">
            Todas
        </button>
        @foreach ($this->estadosFiltrables as $estado)
            <button type="button" role="tab" wire:click="$set('filtroEstado', '{{ $estado }}')" aria-selected="{{ $filtroEstado === $estado ? 'true' : 'false' }}"
                class="fp-pestana">
                {{ ucfirst($estado) }}
            </button>
        @endforeach
    </div>

    <div class="relative fp-tarjeta fp-tabla overflow-hidden">
        <x-ui.cargando />
        <table class="w-full text-sm">
            <thead class="bg-fp-page text-left text-[11px] uppercase tracking-wide text-fp-text-muted">
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
                            @if ($d->categoriasDirectorio->isNotEmpty())
                                <div class="mt-1 flex flex-wrap gap-1">
                                    @foreach ($d->categoriasDirectorio as $categoria)
                                        <x-panel.insignia class="text-[11px]">{{ $categoria->nombre }}</x-panel.insignia>
                                    @endforeach
                                </div>
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            <x-panel.insignia :variante="['activa' => 'success', 'pendiente' => 'warning', 'suspendida' => 'danger'][$d->estado] ?? 'neutral'"
                                :viva="$d->estado === 'pendiente'" punto>{{ ucfirst($d->estado) }}</x-panel.insignia>
                            @if ($d->estado === 'rechazada' && $d->motivo_rechazo)
                                <div class="text-xs text-slate-500 mt-1 max-w-xs">Motivo: {{ $d->motivo_rechazo }}</div>
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            <button wire:click="toggleMarketplace({{ $d->id }})"
                                class="fp-accion {{ $d->marketplace_visible ? 'fp-accion-exito' : 'fp-accion-neutra' }}" aria-pressed="{{ $d->marketplace_visible ? 'true' : 'false' }}">
                                {{ $d->marketplace_visible ? 'Visible' : 'Oculta' }}
                            </button>
                        </td>
                        <td class="px-4 py-3"><div class="flex flex-wrap gap-1">
                            <button wire:click="verDatos({{ $d->id }})"
                                class="fp-accion">Ver datos</button>
                            <button wire:click="abrirEdicion({{ $d->id }})"
                                class="fp-accion fp-accion-primaria">Editar</button>
                            @if ($d->estado === 'pendiente')
                                <button wire:click="aprobar({{ $d->id }})"
                                    class="fp-accion fp-accion-exito">Aprobar</button>
                                <button wire:click="abrirRechazo({{ $d->id }})"
                                    class="fp-accion fp-accion-peligro">Rechazar</button>
                            @endif
                            @if ($d->estado === 'activa')
                                <button wire:click="suspender({{ $d->id }})"
                                    wire:confirm="Su personal, clientes mayoristas y clientes no podrán usar FootwearPoint hasta reactivarla. Su información se conserva. ¿Continuar?"
                                    class="fp-accion fp-accion-peligro">Suspender</button>
                            @endif
                            @if ($d->estado === 'suspendida')
                                <button wire:click="reactivar({{ $d->id }})"
                                    class="fp-accion fp-accion-primaria">Reactivar</button>
                            @endif
                            @if (in_array($d->estado, ['activa', 'suspendida']))
                                @php
                                    $tienePlan = $d->suscripciones->where('estado', 'activa')->isNotEmpty();
                                @endphp
                                <button wire:click="abrirSuscripcion({{ $d->id }})"
                                    class="fp-accion">
                                    @if ($tienePlan)
                                        Cambiar plan
                                    @else
                                        Asignar plan
                                    @endif
                                </button>
                            @endif
                        </div></td>
                    </tr>
                @empty
                    <x-panel.vacio colspan="5" mensaje="No hay distribuidoras con este filtro." />
                @endforelse
            </tbody>
        </table>
    </div>
</div>
