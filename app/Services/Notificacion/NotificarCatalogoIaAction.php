<?php

namespace App\Services\Notificacion;

use App\Models\ImportacionCatalogo;
use App\Models\Notificacion;
use App\Models\Usuario;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Avisa en la campana cuando la IA termina de leer un catálogo o falla.
 *
 * Le llega a quien lo subió (siempre un admin general). Si esa persona ya no
 * existe, a todos los admin general, para que el aviso no se pierda.
 *
 * Corre en el worker, sin sesión: la notificación queda sin distribuidora,
 * igual que el panel del admin general. Un aviso que falla nunca deshace el
 * análisis (que ya costó dinero): se reporta y ya.
 */
class NotificarCatalogoIaAction
{
    public const TIPO_LISTO = 'catalogo_ia_listo';
    public const TIPO_ERROR = 'catalogo_ia_error';
    public const ENTIDAD = 'importacion_catalogo';

    public function listo(ImportacionCatalogo $importacion): void
    {
        $productos = $importacion->productosStaging()->count();
        $linea = $this->linea($importacion);

        $this->crear(
            $importacion,
            self::TIPO_LISTO,
            "El catálogo de {$linea} está listo para revisar",
            "La IA terminó de leerlo: {$productos} ".($productos === 1 ? 'producto' : 'productos')
                .' esperan tu revisión antes de entrar al catálogo.',
        );
    }

    public function fallo(ImportacionCatalogo $importacion, ?string $motivo = null): void
    {
        $linea = $this->linea($importacion);
        $motivo = trim((string) ($motivo ?? $importacion->mensaje_error));

        $this->crear(
            $importacion,
            self::TIPO_ERROR,
            "Falló el análisis del catálogo de {$linea}",
            ($motivo !== '' ? $motivo.' ' : '').'Puedes reintentarlo desde Catálogos con IA.',
        );
    }

    private function crear(ImportacionCatalogo $importacion, string $tipo, string $titulo, string $mensaje): void
    {
        try {
            foreach ($this->destinatarios($importacion) as $usuarioId) {
                Notificacion::create([
                    'usuario_id'       => $usuarioId,
                    'distribuidora_id' => null,
                    'tipo'             => $tipo,
                    'titulo'           => $titulo,
                    'mensaje'          => $mensaje,
                    'leida_at'         => null,
                    'entidad_tipo'     => self::ENTIDAD,
                    'entidad_id'       => $importacion->id,
                ]);
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

    /** @return list<int> */
    private function destinatarios(ImportacionCatalogo $importacion): array
    {
        $quienSubio = $importacion->iniciada_por_usuario_id;

        if ($quienSubio !== null && Usuario::whereKey($quienSubio)->exists()) {
            return [(int) $quienSubio];
        }

        $roles = config('permission.table_names.roles', 'roles');
        $pivote = config('permission.table_names.model_has_roles', 'model_has_roles');

        return DB::table($pivote)
            ->join($roles, "{$roles}.id", '=', "{$pivote}.role_id")
            ->where("{$roles}.name", 'admin_general')
            ->where("{$pivote}.model_type", (new Usuario)->getMorphClass())
            ->distinct()
            ->pluck("{$pivote}.model_id")
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    private function linea(ImportacionCatalogo $importacion): string
    {
        return $importacion->linea?->nombre ?? 'la línea';
    }
}
