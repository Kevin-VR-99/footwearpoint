# Métricas: rendimiento y lo que queda para la fase 2

Tarea: TG-397 (A37), plan S5-89 (punto 21 del Plan v13).

Este documento dice qué se hizo para que la pantalla de Métricas
(`/admin/metricas`) no repita trabajo, y qué métricas todavía no se pueden
medir. La lista de pendientes es la misma que se ve en la pantalla, en el
bloque «Lo que todavía no se mide (fase 2)». La fuente es
`App\Services\Metricas\PendientesDeMetricas` y una prueba
(`RendimientoDeMetricasTest`) revisa que este archivo y la pantalla no se
separen.

## Qué se hizo para el rendimiento

### Consultas agregadas sin repetir

Las cifras ya se agrupaban en la base de datos (`SUM`, `COUNT(DISTINCT)` y
`GROUP BY` por tramo), no en PHP. Lo que sí sobraba era repetir la misma
consulta: las series pedían dos veces el total del periodo (una para el total
y otra para la tendencia), en las tres gráficas. Ahora se pide una vez y se
reutiliza. Las series pasan de 12 a 9 consultas para un periodo de 30 días.
No cambia ninguna fórmula: el total sigue saliendo de su propia consulta, no
de sumar las barras.

### Índices

Migración `2026_11_25_100000_agrega_indices_de_metricas`. Solo agrega
índices; `down()` quita exactamente los mismos.

| Tabla | Índice | Para qué consulta |
| --- | --- | --- |
| `pagos` | `ix_pagos_tipo_estado_fecha` (tipo, estado, fecha_pago) | Ingresos de suscripción de toda la plataforma |
| `ventas_directas` | `ix_ventas_directas_fecha` (fecha_venta) | Ventas de tienda de toda la plataforma |
| `suscripciones` | `ix_suscripciones_estado_plan` (estado, plan_id, distribuidora_id) | Plan más contratado |
| `actividad_diaria` | `ix_actividad_diaria_fecha_usuario` (fecha, usuario_id) | Usuarios activos (personas distintas) |

Los índices que ya había en `pagos` y `ventas_directas` empiezan por
`distribuidora_id` y no sirven cuando Métricas pregunta por toda la
plataforma. Ninguna llave foránea depende de los índices nuevos.

### Caché

`App\Services\Metricas\CacheDeMetricas` guarda los indicadores y las series
**5 minutos**, con una llave que lleva el periodo (fecha de inicio y de fin) y
los filtros de distribuidora y plan. Usa el almacén de caché que ya tiene
configurado la aplicación; no hace falta configurar nada nuevo.

- La pantalla dice a qué hora (hora de Ciudad de México) se calcularon las
  cifras y tiene el botón **Actualizar ahora** para recalcular esa vista.
- Un error no se guarda: si el cálculo falla, la siguiente visita lo vuelve
  a intentar y las gráficas siguen ofreciendo «Reintentar».
- Si el almacén de caché falla, la pantalla calcula directo y el error queda
  reportado.
- Al cambiar el día cambia la llave, así que nunca se ve el periodo de ayer
  como si fuera el de hoy.
- Solo el admin general llega a esta pantalla, y lo guardado son cifras
  agregadas, sin datos personales.

## Lo que todavía no se mide (fase 2)

Ninguna de estas métricas aparece en la pantalla con un número, porque hoy no
hay de dónde sacarlo sin inventarlo.

### Salud del servidor

- **Por qué no se mide:** los errores pueden quedar en Sentry y en los
  registros de Railway, pero esta pantalla no los lee. Los tiempos de respuesta
  y el estado de las colas no se guardan en ningún lugar que se pueda consultar
  desde aquí.
- **Qué haría falta:** decidir de dónde se leen (por ejemplo, Sentry o las
  métricas de Railway) y con un acceso de solo lectura que nunca se enseñe en
  pantalla.

### Quién está en línea ahora

- **Por qué no se mide:** el registro de actividad guarda solo persona, día y
  canal, como pide la política aprobada. No guarda la hora ni si la sesión
  sigue abierta, así que cualquier «en línea» sería inventado.
- **Qué haría falta:** decidir si se quiere medir y con qué regla de
  privacidad antes de guardar nada más.

### Suscripciones por vencer

- **Por qué no se mide:** todavía no está fijado cuántos días antes del
  vencimiento cuenta como «por vencer».
- **Qué haría falta:** fijar ese número de días (el plan propone 7).

### Volumen de pedidos

- **Por qué no se mide:** no está acotado si se enseña solo cuántos pedidos
  hubo o también el importe, y el importe no se debe confundir con las ventas
  de mostrador de cada tienda.
- **Qué haría falta:** decidir si se muestra el conteo, el importe o los dos.

### Distribuidoras activas a lo largo del tiempo

- **Por qué no se mide:** el sistema guarda el estado actual de cada
  distribuidora (se ve en Distribuidoras), no un corte por día, así que no hay
  de dónde sacar una serie hacia atrás.
- **Qué haría falta:** guardar un corte diario del estado. La serie solo
  contaría desde el día en que empiece.

## Sin secretos en la pantalla

La pantalla de Métricas solo enseña cifras agregadas y texto fijo. No enseña
llaves, tokens, contraseñas, nombres de variables de entorno ni datos de
conexión. `RendimientoDeMetricasTest` pone valores de prueba en esas claves de
configuración y revisa que no aparezcan en el HTML, ni en la primera carga ni
después de cargar las cifras.
