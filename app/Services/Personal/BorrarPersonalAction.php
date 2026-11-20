<?php

namespace App\Services\Personal;

use App\Models\Usuario;
use App\Services\Auditoria\RegistrarAuditoriaAction;
use App\Services\Perfil\FotoPerfil;
use App\Support\PersonalPlataforma;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * TG-338 (K40) — Borra una cuenta del personal que no tiene historial.
 *
 * Mismo criterio que EliminarPersonaAction: nunca se borra historial. Cuenta
 * como historial lo que la persona hizo (auditoría con su usuario_id), sus
 * aceptaciones legales, los pedidos que capturó, cualquier registro de cliente
 * y cualquier fila que la referencie (la llave foránea es la última red). Los
 * registros de auditoría donde solo aparece como entidad no cuentan: toda
 * alta desde K38 deja uno.
 *
 * Todo se borra en una transacción; la foto de R2, después de confirmarla.
 */
class BorrarPersonalAction
{
    public const MENSAJE_HISTORIAL = 'y no se puede borrar sin perderlo. Desactívala: ya no podrá entrar y su historial se conserva.';

    public function __construct(
        private ConfirmarPasswordAdminAction $confirmar,
        private SalvaguardasPersonal $salvaguardas,
        private RegistrarAuditoriaAction $auditoria,
        private FotoPerfil $fotos,
        private AvisarCambioPersonalAction $aviso,
    ) {
    }

    public function ejecutar(Usuario $admin, Usuario $persona, string $password): string
    {
        $this->confirmar->exigir($admin, $password);
        PersonalPlataforma::exigirPersonal($persona);

        try {
            $cuenta = DB::transaction(function () use ($admin, $persona) {
                $cuenta = Usuario::query()->lockForUpdate()->findOrFail($persona->id);

                $this->salvaguardas->exigir($admin, $cuenta, 'borrar');
                $this->exigirSinHistorial($cuenta);

                $this->auditoria->ejecutar('personal.borrado', 'usuario', (int) $cuenta->id, [
                    'nombre' => $cuenta->nombre,
                    'email' => $cuenta->email,
                    'roles' => $this->salvaguardas->asignaciones($cuenta),
                ], null);

                $this->borrar($cuenta);

                return $cuenta;
            });
        } catch (QueryException $e) {
            if (($e->errorInfo[0] ?? null) !== '23000' || (int) ($e->errorInfo[1] ?? 0) !== 1451) {
                throw $e;
            }

            $this->rechazar('Esta cuenta tiene movimientos registrados '.self::MENSAJE_HISTORIAL);
        }

        // La transacción ya se confirmó: la cuenta no existe y la foto sobra.
        $this->fotos->borrar($cuenta->foto_url);

        $fallo = $this->aviso->ejecutar($cuenta, 'Un administrador borró tu cuenta de FootwearPoint. Ya no puedes entrar con ella.');

        return $fallo ?? 'Cuenta borrada.';
    }

    private function exigirSinHistorial(Usuario $cuenta): void
    {
        $staffIds = DB::table('distribuidora_staff')->where('usuario_id', $cuenta->id)->pluck('id');

        $partes = array_filter([
            'movimientos en la auditoría' => DB::table('auditorias')->where('usuario_id', $cuenta->id)->count(),
            'aceptaciones de documentos legales' => DB::table('aceptaciones_legales')->where('usuario_id', $cuenta->id)->count(),
            'pedidos capturados' => $staffIds->isEmpty() ? 0 : DB::table('pedidos')->whereIn('capturado_por_staff_id', $staffIds)->count(),
            'registros como cliente' => DB::table('revendedores')->where('usuario_id', $cuenta->id)->count()
                + DB::table('clientes_directos')->where('usuario_id', $cuenta->id)->count(),
        ]);

        if ($partes !== []) {
            $this->rechazar('Esta cuenta tiene historial ('.implode(', ', array_keys($partes)).') '.self::MENSAJE_HISTORIAL);
        }
    }

    private function borrar(Usuario $cuenta): void
    {
        $tipo = $cuenta->getMorphClass();

        DB::table('distribuidora_staff')->where('usuario_id', $cuenta->id)->delete();
        DB::table(config('permission.table_names.model_has_roles', 'model_has_roles'))
            ->where('model_type', $tipo)->where('model_id', $cuenta->id)->delete();
        DB::table(config('permission.table_names.model_has_permissions', 'model_has_permissions'))
            ->where('model_type', $tipo)->where('model_id', $cuenta->id)->delete();
        $cuenta->tokens()->delete();
        DB::table('cambios_contacto_pendientes')->where('usuario_id', $cuenta->id)->delete();
        DB::table(config('auth.passwords.users.table', 'password_reset_tokens'))->where('email', $cuenta->email)->delete();

        if (config('session.driver') === 'database') {
            DB::table(config('session.table', 'sessions'))->where('user_id', $cuenta->id)->delete();
        }

        $cuenta->delete();
    }

    private function rechazar(string $mensaje): never
    {
        throw ValidationException::withMessages([SalvaguardasPersonal::CAMPO => [$mensaje]]);
    }
}
