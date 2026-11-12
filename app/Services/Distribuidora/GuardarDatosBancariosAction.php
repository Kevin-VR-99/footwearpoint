<?php

namespace App\Services\Distribuidora;

use App\Mail\CambioClabeMail;
use App\Models\ConfiguracionDistribuidora;
use App\Models\DistribuidoraStaff;
use App\Services\Auditoria\RegistrarAuditoriaAction;
use App\Support\Clabe;
use App\Support\Tenant;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;
use Throwable;

/**
 * El admin guarda banco, CLABE y titular. Un cambio de CLABE queda en la
 * auditoría y se avisa por correo al administrador de la distribuidora.
 */
class GuardarDatosBancariosAction
{
    public function __construct(private RegistrarAuditoriaAction $auditoria)
    {
    }

    public function ejecutar(array $datos): ConfiguracionDistribuidora
    {
        abort_if(Tenant::id() === null, 403, 'No se pudo determinar la distribuidora.');
        abort_unless($this->esAdmin(), 403, 'Solo el admin de la distribuidora puede cambiar los datos bancarios.');

        $banco = trim((string) ($datos['banco'] ?? ''));
        $titular = trim((string) ($datos['titular'] ?? ''));
        $clabe = Clabe::normalizar((string) ($datos['clabe'] ?? ''));

        $vacio = $banco === '' && $titular === '' && $clabe === '';

        if (! $vacio) {
            $errores = [];

            if ($banco === '' || mb_strlen($banco) > 80) {
                $errores['banco'] = 'Escribe el banco, con 80 caracteres como máximo.';
            }

            if ($titular === '' || mb_strlen($titular) > 150) {
                $errores['titular'] = 'Escribe el titular de la cuenta, con 150 caracteres como máximo.';
            }

            if (! Clabe::esValida($clabe)) {
                $errores['clabe'] = Clabe::MENSAJE;
            }

            if ($errores !== []) {
                throw ValidationException::withMessages($errores);
            }
        }

        $config = ConfiguracionDistribuidora::query()->firstOrFail();
        $antes = [
            'banco' => $config->banco,
            'clabe' => $config->clabe,
            'titular' => $config->titular_cuenta,
        ];
        $despues = [
            'banco' => $vacio ? null : $banco,
            'clabe' => $vacio ? null : $clabe,
            'titular' => $vacio ? null : $titular,
        ];

        if ($antes === $despues) {
            return $config;
        }

        $config->update([
            'banco' => $despues['banco'],
            'clabe' => $despues['clabe'],
            'titular_cuenta' => $despues['titular'],
        ]);

        $this->auditoria->ejecutar(
            'datos_bancarios.actualizados',
            'configuracion_distribuidora',
            (int) $config->id,
            $antes,
            $despues,
        );

        if ($antes['clabe'] !== $despues['clabe']) {
            $this->avisarCambioDeClabe($config, $despues['clabe']);
        }

        return $config->fresh();
    }

    private function esAdmin(): bool
    {
        $usuario = Auth::user();
        $distribuidoraId = Tenant::id();

        if ($usuario === null || $distribuidoraId === null) {
            return false;
        }

        $registrar = app(PermissionRegistrar::class);
        $anterior = $registrar->getPermissionsTeamId();

        try {
            $registrar->setPermissionsTeamId($distribuidoraId);
            $usuario->unsetRelation('roles');

            return $usuario->hasRole(ProvisionarRolesDistribuidoraAction::ROL_ADMINISTRADOR);
        } finally {
            $registrar->setPermissionsTeamId($anterior);
            $usuario->unsetRelation('roles');
        }
    }

    private function avisarCambioDeClabe(ConfiguracionDistribuidora $config, ?string $clabeNueva): void
    {
        $terminacion = $clabeNueva === null ? 'se quitó' : 'ahora termina en '.substr($clabeNueva, -4);
        $quien = Auth::user()?->nombreVisible() ?? 'alguien de la distribuidora';
        $mensaje = 'La CLABE de tu distribuidora cambió ('.$terminacion.'). '
            .'Lo hizo '.$quien.'. Si no reconoces este cambio, entra a Configuración → Métodos de pago y revísala. '
            .'FootwearPoint no recibe ni custodia estos pagos.';

        $administradores = DistribuidoraStaff::with('usuario')
            ->where('distribuidora_id', $config->distribuidora_id)
            ->where('tipo', 'administrador')
            ->where('estado', 'activo')
            ->whereNotNull('usuario_id')
            ->get();

        foreach ($administradores as $staff) {
            $correo = trim((string) $staff->usuario?->email);

            if ($correo === '') {
                continue;
            }

            try {
                Mail::to($correo)->send(new CambioClabeMail($mensaje));
            } catch (Throwable $e) {
                report($e);
            }
        }
    }
}
