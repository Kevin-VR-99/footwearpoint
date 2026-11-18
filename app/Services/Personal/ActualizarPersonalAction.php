<?php

namespace App\Services\Personal;

use App\Models\Usuario;
use App\Services\Auditoria\AuditarCambios;
use App\Services\Perfil\FotoPerfil;
use App\Support\PersonalPlataforma;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * TG-336 (K38) — El admin general corrige nombre, teléfono, dirección y foto.
 * Correo, rol y contraseña son de K39. En la auditoría queda solo lo que cambió.
 */
class ActualizarPersonalAction
{
    public function __construct(
        private AuditarCambios $auditar,
        private FotoPerfil $fotos,
    ) {
    }

    /**
     * @param  array{nombre?: mixed, telefono?: mixed, direccion?: mixed}  $datos
     *
     * @throws ValidationException con los errores por campo.
     */
    public function ejecutar(Usuario $usuario, array $datos, ?UploadedFile $foto = null): Usuario
    {
        $limpios = Validator::make([
            'nombre' => trim((string) ($datos['nombre'] ?? '')),
            'telefono' => trim((string) ($datos['telefono'] ?? '')) ?: null,
            'direccion' => trim((string) ($datos['direccion'] ?? '')) ?: null,
            'foto' => $foto,
        ], [
            'nombre' => ['required', 'string', 'max:150'],
            'telefono' => ['nullable', 'string', 'max:30'],
            'direccion' => ['nullable', 'string', 'max:300'],
            'foto' => ['nullable', ...FotoPerfil::reglas()],
        ], [
            'nombre.required' => 'El nombre es obligatorio.',
            'nombre.max' => 'El nombre no puede pasar de 150 caracteres.',
            'telefono.max' => 'El teléfono no puede pasar de 30 caracteres.',
            'direccion.max' => 'La dirección no puede pasar de 300 caracteres.',
            ...FotoPerfil::mensajes('foto'),
        ])->validate();

        $usuario->forceFill(['nombre' => $limpios['nombre'], 'telefono' => $limpios['telefono']]);

        // El aviso de privacidad no declara la dirección del admin general.
        if (PersonalPlataforma::tieneRolDeDistribuidora((int) $usuario->id)) {
            $usuario->direccion = $limpios['direccion'];
        }

        $fotoAnterior = $usuario->foto_url;
        $fotoNueva = $foto ? $this->fotos->guardar($foto, 'personal') : null;

        if ($fotoNueva !== null) {
            $usuario->foto_url = $fotoNueva;
        }

        try {
            $this->auditar->guardar($usuario, 'personal.actualizado', 'usuario');
        } catch (Throwable $e) {
            $this->fotos->borrar($fotoNueva);

            throw $e;
        }

        if ($fotoNueva !== null) {
            $this->fotos->borrar($fotoAnterior);
        }

        return $usuario;
    }
}
