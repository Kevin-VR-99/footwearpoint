<?php

namespace App\Support\Services;

use App\Models\Notificacion;
use App\Support\GruposNotificacion;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * TG-196: resumen de la campana por grupos (icono, título, vista previa del
 * último mensaje y contador de no leídas).
 */
class AgruparNotificaciones
{
    public const LARGO_VISTA_PREVIA = 70;

    /**
     * @return Collection<int, array{clave:string,titulo:string,icono:string,no_leidas:int,total:int,ultima:?Notificacion,vista_previa:?string}>
     */
    public function resumen(int $usuarioId): Collection
    {
        $notificaciones = Notificacion::query()
            ->where('usuario_id', $usuarioId)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get(['id', 'tipo', 'titulo', 'mensaje', 'leida_at', 'created_at']);

        return $this->agrupar($notificaciones);
    }

    /** @param iterable<Notificacion> $notificaciones ordenadas de la más nueva a la más vieja */
    public function agrupar(iterable $notificaciones): Collection
    {
        $porGrupo = collect($notificaciones)->groupBy(fn ($n) => GruposNotificacion::de($n->tipo));

        return collect(GruposNotificacion::GRUPOS)->map(function (array $meta, string $clave) use ($porGrupo) {
            $lista = $porGrupo->get($clave, collect());
            $ultima = $lista->first();

            return [
                'clave'        => $clave,
                'titulo'       => $meta['titulo'],
                'icono'        => $meta['icono'],
                'no_leidas'    => $lista->whereNull('leida_at')->count(),
                'total'        => $lista->count(),
                'ultima'       => $ultima,
                'vista_previa' => $ultima ? Str::limit(trim($ultima->titulo.' · '.$ultima->mensaje), self::LARGO_VISTA_PREVIA) : null,
            ];
        })->values();
    }

    /** Filtra un query de notificaciones a un grupo. */
    public function filtrarPorGrupo($query, string $grupo)
    {
        if (! GruposNotificacion::existe($grupo)) {
            return $query;
        }

        $propios = GruposNotificacion::tiposDe($grupo);
        $otros = array_keys(array_diff_key(GruposNotificacion::TIPOS, array_flip($propios)));

        return $query->where(function ($q) use ($grupo, $propios, $otros) {
            if ($propios !== []) {
                $q->orWhereIn('tipo', $propios);
            }
            foreach (GruposNotificacion::PREFIJOS as $prefijo => $g) {
                if ($g === $grupo) {
                    $q->orWhere(fn ($w) => $w->where('tipo', 'like', $prefijo.'%')->whereNotIn('tipo', $otros));
                }
            }
            if ($grupo === GruposNotificacion::GENERALES) {
                $q->orWhere(function ($w) {
                    $w->whereNotIn('tipo', array_keys(GruposNotificacion::TIPOS));
                    foreach (array_keys(GruposNotificacion::PREFIJOS) as $prefijo) {
                        $w->where('tipo', 'not like', $prefijo.'%');
                    }
                })->orWhereNull('tipo');
            }
        });
    }
}
