<?php

namespace App\Services\Metricas;

/**
 * TG-397 (A37) — Lo que Métricas todavía no mide, y por qué.
 *
 * El plan (punto 21, S5-89) pide dejar escrito como fase 2 lo que hoy no se
 * puede medir, en vez de enseñarlo con un número sacado de la nada. Esta es
 * la lista que se ve en la pantalla y la misma que está en
 * docs/metricas-fase2.md (una prueba vigila que no se separen).
 *
 * SIN SECRETOS: aquí solo hay texto fijo. Nada de llaves, tokens, nombres de
 * variables de entorno ni direcciones internas; la pantalla la ve cualquier
 * admin general y una captura acaba en cualquier lado.
 */
class PendientesDeMetricas
{
    /**
     * @return list<array{clave: string, titulo: string, motivo: string, falta: string}>
     */
    public static function lista(): array
    {
        return [
            [
                'clave' => 'salud-servidor',
                'titulo' => 'Salud del servidor',
                'motivo' => 'Los errores pueden quedar en Sentry y en los registros de Railway, pero esta pantalla no los lee. '
                    .'Los tiempos de respuesta y el estado de las colas no se guardan en ningún lugar que se pueda consultar desde aquí.',
                'falta' => 'Decidir de dónde se leen (por ejemplo, Sentry o las métricas de Railway) y con un acceso de solo lectura '
                    .'que nunca se enseñe en pantalla.',
            ],
            [
                'clave' => 'presencia',
                'titulo' => 'Quién está en línea ahora',
                'motivo' => 'El registro de actividad guarda solo persona, día y canal, como pide la política aprobada. '
                    .'No guarda la hora ni si la sesión sigue abierta, así que cualquier «en línea» sería inventado.',
                'falta' => 'Decidir si se quiere medir y con qué regla de privacidad antes de guardar nada más.',
            ],
            [
                'clave' => 'por-vencer',
                'titulo' => 'Suscripciones por vencer',
                'motivo' => 'Todavía no está fijado cuántos días antes del vencimiento cuenta como «por vencer».',
                'falta' => 'Fijar ese número de días (el plan propone 7).',
            ],
            [
                'clave' => 'volumen-pedidos',
                'titulo' => 'Volumen de pedidos',
                'motivo' => 'No está acotado si se enseña solo cuántos pedidos hubo o también el importe, '
                    .'y el importe no se debe confundir con las ventas de mostrador de cada tienda.',
                'falta' => 'Decidir si se muestra el conteo, el importe o los dos.',
            ],
            [
                'clave' => 'distribuidoras-en-el-tiempo',
                'titulo' => 'Distribuidoras activas a lo largo del tiempo',
                'motivo' => 'El sistema guarda el estado actual de cada distribuidora (se ve en Distribuidoras), '
                    .'no un corte por día, así que no hay de dónde sacar una serie hacia atrás.',
                'falta' => 'Guardar un corte diario del estado. La serie solo contaría desde el día en que empiece.',
            ],
        ];
    }
}
