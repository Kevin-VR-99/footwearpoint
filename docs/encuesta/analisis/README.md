# Análisis de la encuesta de satisfacción (TG-256)

Script para analizar el CSV que exporta Google Forms con la encuesta de [`docs/encuesta-satisfaccion.md`](../../encuesta-satisfaccion.md). La captura del formulario está en [`../guia-google-forms.md`](../guia-google-forms.md).

## Requisitos

Python 3.10 o más reciente, con **pandas** y **matplotlib** nada más:

```bash
python3 -m venv .venv
. .venv/bin/activate            # Windows: .venv\Scripts\activate
pip install pandas matplotlib
```

## Uso

```bash
cd docs/encuesta/analisis
python analizar_encuesta.py datos/respuestas.csv --salida salida
```

| Opción | Por defecto | Qué hace |
|---|---|---|
| `csv` | — | CSV exportado de Google Forms |
| `--salida` | `salida` | Carpeta donde se escriben el reporte, las tablas y las gráficas |
| `--min-n` | `5` | Con menos personas, un módulo marcado para mejora lleva la nota "pocos datos" |

`datos/` y `salida/` están en `.gitignore`: **las respuestas reales y las salidas no se suben al repo** (las abiertas pueden traer datos personales).

## Qué calcula

- **Por pregunta, módulo y rol:** n, promedio, desviación estándar y % de respuestas 1–2. En el módulo se juntan sus dos preguntas Likert; "Personas" es cuántos contestaron al menos una.
- **NPS** (G5) total y por rol: % promotores (9–10) − % detractores (0–6).
- **Módulos para mejora:** promedio < 3.5 **o** más del 30 % de respuestas 1–2.
- **Alfa de Cronbach** por bloque (General G1–G4 y cada módulo), con casos completos. Los módulos solo tienen 2 ítems: su alfa es orientativo.
- Lista de **respuestas abiertas** por pregunta, para agruparlas por tema.

Limpieza que hace antes de calcular:
- "No lo uso" no cuenta;
- si la persona contestó `No` en "¿Usas este módulo?" (`Xn.0`), sus respuestas de ese módulo no cuentan;
- respuestas de módulos que no son del rol (un cliente en W1, un empleado en W9, un cliente en A8) se ignoran y se avisan;
- si una pregunta aparece en dos columnas, se unen por ID.

## Salidas (en `--salida`)

| Archivo | Contenido |
|---|---|
| `reporte.md` | Reporte completo en markdown, con las gráficas enlazadas |
| `por_pregunta.csv`, `por_modulo.csv`, `nps.csv`, `cronbach.csv` | Las tablas, para pegarlas o graficarlas en otro lado |
| `promedio_por_modulo.png` | Promedio por módulo con el umbral 3.5 (rojo = mejora) |
| `distribucion_por_modulo.png` | % de respuestas 1–5 por módulo |
| `modulo_por_rol.png` | Mapa de calor módulo × rol |
| `nps.png` | Distribución de G5 |

## Datos de ejemplo (FICTICIOS)

`ejemplo_respuestas_FICTICIAS.csv` son **20 respuestas inventadas** solo para probar el script:
- la columna `AVISO` dice en cada fila *"DATOS FICTICIOS DE EJEMPLO - NO SON RESPUESTAS REALES"*;
- las fechas son de 2000-01 y las abiertas empiezan con `[FICTICIO]`;
- W5 y A4 se generaron a propósito con calificaciones bajas para que el reporte muestre módulos para mejora; el resto es aleatorio, por eso su alfa de Cronbach sale bajo o negativo.

```bash
python analizar_encuesta.py ejemplo_respuestas_FICTICIAS.csv --salida salida
```

Cuando el nombre del archivo o una columna dice "FICTICI", el reporte y las gráficas llevan la leyenda **DATOS FICTICIOS DE EJEMPLO**. **No uses estos resultados en `resultados.md`.**

## Requisitos del CSV

El script reconoce cada pregunta por el ID al inicio del encabezado (`G1.`, `W3.2.`, `P0.1 `). Si cambian los títulos en Forms, conserva el ID. Las opciones Likert deben empezar con el número (`4 - De acuerdo`).
