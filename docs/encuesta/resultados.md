# Resultados de la encuesta de satisfacción

> TG-256 (R14) · Historia E18-04. Plantilla para llenar con los **datos reales**.
> Las cifras salen de `analisis/analizar_encuesta.py` (ver `analisis/README.md`). No copiar nada del ejemplo ficticio.

## 1. Ficha de aplicación

| Dato | Valor |
|---|---|
| Formulario | _link de Google Forms_ |
| Fechas de aplicación | _del ___ al ___ |
| Versión del sistema evaluada | _rama / commit / fecha del despliegue_ |
| Plataformas | Web (panel) y app móvil |
| Cómo se invitó | _correo, WhatsApp, en persona…_ |
| Respuestas válidas | _n_ (meta: ≥ 15) |
| Respuestas descartadas y motivo | _n — p. ej. pruebas del equipo_ |

Participantes: usuarios **externos al equipo**, con el sistema funcionando. El admin general no responde.

| Rol | Meta | Respuestas |
|---|---|---|
| Administrador(a) de distribuidora | 3 | |
| Empleado(a) de distribuidora | 3 | |
| Cliente mayorista | 4 | |
| Cliente | 5 | |
| **Total** | **15** | |

## 2. Método

- Instrumento: `docs/encuesta-satisfaccion.md` (Likert 1–5 con "No lo uso", NPS 0–10, preguntas abiertas).
- Estadística descriptiva por pregunta, módulo y rol: n, promedio, desviación estándar, % de respuestas 1–2.
- NPS = % promotores (9–10) − % detractores (0–6).
- Criterio de mejora: promedio < 3.5 o más del 30 % de respuestas 1–2.
- Fiabilidad: alfa de Cronbach por bloque (≥ 0.7 aceptable).
- Limitaciones: _muestra pequeña y no aleatoria, módulos con pocos usuarios, etc._

## 3. Resultados generales

| Indicador | Valor |
|---|---|
| Satisfacción general (G1), promedio | |
| Promedio del bloque General (G1–G4) | |
| NPS | |
| Alfa de Cronbach, bloque General | |

_Interpretación:_

![Promedio por módulo](analisis/salida/promedio_por_modulo.png)

## 4. NPS por rol

| Rol | n | % promotores | % pasivos | % detractores | NPS |
|---|---|---|---|---|---|
| Todos | | | | | |
| Administrador(a) de distribuidora | | | | | |
| Empleado(a) de distribuidora | | | | | |
| Cliente mayorista | | | | | |
| Cliente | | | | | |

_Interpretación:_

## 5. Resultados por módulo

| Módulo | Personas | Promedio | Desv. | % 1–2 | ¿Mejora? |
|---|---|---|---|---|---|
| W1 Catálogo y precios mayoristas | | | | | |
| W2 Stock | | | | | |
| W3 Punto de venta | | | | | |
| W4 Pedidos y entregas | | | | | |
| W5 Ciclo de compra y solicitud a fábrica | | | | | |
| W6 Vales (web) | | | | | |
| W7 Cambios de producto (web) | | | | | |
| W8 Reportes | | | | | |
| W9 Configuración, permisos y usuarios | | | | | |
| A1 Acceso y cuenta | | | | | |
| A2 Catálogo (app) | | | | | |
| A3 Hacer un pedido | | | | | |
| A4 Mis pedidos y pagos | | | | | |
| A5 Vales (app) | | | | | |
| A6 Cambios de producto (app) | | | | | |
| A7 Notificaciones | | | | | |
| A8 Mis clientes | | | | | |

_Interpretación (diferencias entre roles, ver mapa módulo × rol):_

![Módulo por rol](analisis/salida/modulo_por_rol.png)

## 6. Fiabilidad (alfa de Cronbach)

| Bloque | Ítems | Casos completos | Alfa | Interpretación |
|---|---|---|---|---|
| General | G1–G4 | | | |
| _módulos con datos suficientes_ | | | | |

_Nota: los módulos tienen 2 ítems; su alfa es orientativo._

## 7. Respuestas abiertas

| Módulo | Tema (error / falta función / confuso / lento / otro) | Veces | Ejemplo textual (sin datos personales) |
|---|---|---|---|
| | | | |

## 8. Mejoras detectadas

| # | Módulo | Origen (ID de pregunta) | Evidencia | Prioridad | Tarea en Taiga |
|---|---|---|---|---|---|
| 1 | | | | | |

## 9. Conclusiones

_Resumen en 3 a 5 puntos: qué funciona bien, qué hay que mejorar primero y qué se recomienda para la siguiente versión._

## Anexos

- Gráficas: `analisis/salida/*.png` (si se publican, copiarlas a `docs/encuesta/img/` porque `salida/` no se sube).
- Tablas: `por_pregunta.csv`, `por_modulo.csv`, `nps.csv`, `cronbach.csv`.
- El CSV con las respuestas **no** se sube al repo.
