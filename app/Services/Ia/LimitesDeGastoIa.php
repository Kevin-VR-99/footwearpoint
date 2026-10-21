<?php

namespace App\Services\Ia;

use App\Models\ImportacionCatalogo;

/**
 * Topes de gasto de la lectura de catálogos con IA (config/ia.php).
 *
 * Se revisan antes de mandar a la cola (para avisar en pantalla) y otra vez
 * en el worker (por si algo lo mandó por otro camino). Un tope en 0 no aplica.
 */
class LimitesDeGastoIa
{
    /** Motivo en español para no procesar, o null si se puede. */
    public function motivoParaNoProcesar(ImportacionCatalogo $importacion): ?string
    {
        return $this->motivoPorGastoDelMes() ?? $this->motivoPorImportacionesDelDia($importacion);
    }

    public function motivoPorGastoDelMes(): ?string
    {
        $tope = (float) config('ia.max_usd_por_mes');

        if ($tope <= 0) {
            return null;
        }

        $gastado = $this->gastoDelMes();

        if ($gastado < $tope) {
            return null;
        }

        return sprintf(
            'Ya se gastaron %s USD de %s USD permitidos este mes en leer catálogos con la IA. '
            .'Para seguir este mes, el equipo de FootwearPoint tiene que subir el tope (IA_MAX_USD_MES).',
            number_format($gastado, 2), number_format($tope, 2),
        );
    }

    public function motivoPorImportacionesDelDia(ImportacionCatalogo $importacion): ?string
    {
        $tope = (int) config('ia.max_importaciones_por_dia');

        if ($tope <= 0) {
            return null;
        }

        $hoy = ImportacionCatalogo::query()
            ->where('iniciada_por_usuario_id', $importacion->iniciada_por_usuario_id)
            ->where('id', '!=', $importacion->id)
            ->where('procesamiento_iniciado_at', '>=', now()->startOfDay())
            ->count();

        if ($hoy < $tope) {
            return null;
        }

        return "Ya se mandaron a procesar {$tope} catálogos hoy desde esta cuenta, que es el máximo por día. Inténtalo mañana.";
    }

    public function motivoPorPaginas(int $paginas): ?string
    {
        $tope = (int) config('ia.max_paginas');

        if ($tope <= 0 || $paginas <= $tope) {
            return null;
        }

        return "El archivo tiene {$paginas} páginas y el máximo para leer con la IA es {$tope}. Divide el catálogo en partes más chicas y súbelas por separado.";
    }

    /** Suma de lo que costó lo procesado en el mes en curso, en USD. */
    public function gastoDelMes(): float
    {
        $inicio = now()->startOfMonth();

        return (float) ImportacionCatalogo::query()
            ->whereNotNull('costo_usd')
            ->where(fn ($q) => $q->where('procesamiento_iniciado_at', '>=', $inicio)
                ->orWhere(fn ($q) => $q->whereNull('procesamiento_iniciado_at')->where('updated_at', '>=', $inicio)))
            ->sum('costo_usd');
    }
}
