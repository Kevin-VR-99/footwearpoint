# Cotización de la opción B: fotos generadas

Tarea: TG-388 (A28), plan S5-25 (punto 8 del Plan v13).

Este documento cotiza la **opción B** de las fotos: partir del recorte real
que sale del catálogo de la fábrica y pedirle a un modelo de generación de
imágenes las vistas que falten, para llegar a 3 imágenes por producto.

**La opción B no entra al Sprint 5.** Gabriel decidió el 25/10/2026 empezar
con la opción A (ángulos reales del catálogo, recortados) y dejar la B para
después, con cotización previa. Esta es esa cotización. No se programó nada,
no se abrió ninguna cuenta y no se gastó dinero.

Precios consultados el **25/11/2026**. Todos los precios de los proveedores
están en dólares (USD) y sin IVA, tal como los publican.

---

## 1. Qué hay hoy en el sistema

- La importación de catálogos usa Claude (`config/ia.php`, modelo
  `claude-opus-5-5`). Claude **solo analiza imágenes, no las genera ni las
  edita** ([documentación de Anthropic, «Can Claude generate or edit
  images?»](https://docs.claude.com/en/docs/build-with-claude/vision)). Por
  eso la opción B necesita otro proveedor.
- El recorte lo hacen `RasterizarPaginaPdf` y `RecortarFotoDelCatalogo`, y
  `RedimensionarImagenWebp` saca la miniatura (~400 px) y la mediana
  (~1200 px). Con `catalogo:publicar-fotos-ia` (y `--galeria`) el recorte pasa
  a la galería del producto.
- Las fotos viven en `producto_imagenes`, con varias por publicación. Guardar
  3 imágenes no requiere cambiar ese esquema.
- Hay un tope de gasto mensual para la IA (`IA_MAX_USD_MES`, 150 USD por
  omisión), pero **solo cubre la lectura de catálogos con Claude**. La
  generación necesitaría su propio tope.

## 2. La fórmula

La que pide el Plan v13 (tabla de opciones de fotos):

```
costo mensual = productos nuevos × imágenes generadas × precio por imagen
```

Con dos ajustes, ambos **supuestos** que hay que confirmar en una prueba:

- **Imágenes generadas por producto: 2.** El catálogo ya da 1 foto real; se
  generan las otras 2 para llegar a 3.
- **Intentos por imagen aceptada: 1.5.** El admin general tiene que revisar
  cada imagen (lo pide el plan), y algunas se van a rechazar y volver a
  generar. Se supone que de cada 2 imágenes, 1 se repite una vez.

Entonces: `precio por imagen aceptada = precio por intento × 1.5`.

## 3. Proveedores y precio por imagen

Se cotizaron los dos proveedores con API pública de precios por imagen que
pueden **editar a partir de una foto de referencia**, que es lo que necesita
la opción B (no basta con generar desde texto: la vista nueva tiene que ser
el mismo zapato).

| Proveedor y modelo | Precio por imagen (USD) | Fuente |
|---|---|---|
| Google, Gemini 3.1 Flash Lite Image («Nano Banana 2 Lite»), 1024 px | 0.0336 | [Gemini API pricing](https://ai.google.dev/gemini-api/docs/pricing) |
| Google, Gemini Nano Banana 2.1, 1024 px | 0.0336 | [Gemini API pricing](https://ai.google.dev/gemini-api/docs/pricing) |
| Google, Gemini Nano Banana 2.1, 2048 px | 0.0504 | [Gemini API pricing](https://ai.google.dev/gemini-api/docs/pricing) |
| Google, Gemini Nano Banana 2.1 por lotes (Batch), 1024 px | 0.0168 | [Gemini API pricing](https://ai.google.dev/gemini-api/docs/pricing) (salida de imagen a 15 USD por millón de tokens en Batch) |
| Google, Gemini 3.1 Flash Image («Nano Banana 2»), 1024 px | 0.067 | [Gemini API pricing](https://ai.google.dev/gemini-api/docs/pricing) |
| Google, Gemini 3 Pro Image («Nano Banana Pro»), hasta 2048 px | 0.134 + 0.0011 por la foto de entrada | [Gemini API pricing](https://ai.google.dev/gemini-api/docs/pricing) |
| OpenAI, `gpt-image-2` calidad media, 1024×1024 | 0.053 | [OpenAI, guía de generación de imágenes, «Historical output costs»](https://platform.openai.com/docs/guides/image-generation) |
| OpenAI, `gpt-image-2` calidad alta, 1024×1024 | 0.211 | Misma fuente |
| OpenAI, `gpt-image-2` calidad baja, 1024×1024 | 0.006 | Misma fuente |

Notas sobre la tabla:

- **Tamaño.** Basta 1024 px: la imagen más grande que guarda el sistema es la
  mediana de ~1200 px. 2048 px solo haría falta si se quiere zoom.
- **Los modelos más nuevos de OpenAI** (`gpt-image-2.5` y `gpt-image-2.6`,
  variantes Sunburst y Flare) cobran la salida al mismo precio por token
  (30 USD por millón, [pricing de OpenAI](https://platform.openai.com/docs/pricing)),
  pero OpenAI no publica cuántos tokens gasta cada imagen fuera de su
  calculadora, así que **no se les pone precio por imagen aquí**.
- **Calidad baja de OpenAI** (0.006 USD) es para borradores, según la misma
  guía. No se usa en los escenarios porque una foto de tienda no puede verse
  como borrador.
- **La foto de entrada también se cobra.** Google solo publica cuánto cuesta en
  Nano Banana Pro (560 tokens, 0.0011 USD). En los demás modelos de Google y en
  OpenAI el costo de la foto de entrada depende de los tokens que se cuenten, y
  OpenAI aclara que `gpt-image-2` procesa la entrada siempre en alta
  fidelidad. **No se incluye en los escenarios**: hay que medirlo con el campo
  `usage` de las respuestas en la prueba piloto (sección 7).
- **`gemini-2.5-flash-image`** («Nano Banana» original) se descarta: Google lo
  marca como obsoleto y lo apaga el 15/03/2027.
- **Ningún modelo de imagen tiene nivel gratuito** en la API de Gemini (dice
  «Not available» en la columna Free). En el nivel de pago Google indica que el
  contenido **no** se usa para mejorar sus productos, lo cual importa porque
  son fotos de marcas ajenas.

## 4. Conversión a pesos

- **Tipo de cambio:** 19.3668 MXN por dólar, FIX del Banco de México del
  24/11/2026 ([Banxico, tipo de cambio FIX](https://www.banxico.org.mx/tipcamb/tipCamMIAction.do)).
  Es el último FIX publicado al consultar; el cargo real usa el tipo de cambio
  del banco de la tarjeta.
- **IVA:** se suma 16 %. Es un **supuesto**, para tratarlo igual que el
  Análisis de costos v7, donde el cargo de Claude se cuenta con IVA incluido
  porque FootwearPoint no está inscrito en el SAT y no lo acredita.

| Modelo | Por imagen aceptada (MXN con IVA) | Por producto, 2 imágenes (MXN con IVA) |
|---|---|---|
| Nano Banana 2.1 Batch, 1024 px | 0.57 | 1.13 |
| Nano Banana 2.1 o Flash Lite Image, 1024 px | 1.13 | 2.26 |
| Nano Banana 2.1, 2048 px | 1.70 | 3.40 |
| OpenAI `gpt-image-2` media | 1.79 | 3.57 |
| Gemini 3.1 Flash Image, 1024 px | 2.26 | 4.52 |
| Nano Banana Pro | 4.55 | 9.11 |
| OpenAI `gpt-image-2` alta | 7.11 | 14.22 |

Cálculo: `precio USD × 1.5 intentos × 19.3668 × 1.16`.

## 5. Volumen del catálogo

- **Lo que se sabe:** en producción hay **154 productos** (medición del A15,
  citada en `docs/defensa-catalogo-fotos-diseno-metricas.md`). Completar las
  vistas de todo lo que ya existe sería un **gasto de una sola vez**.
- **Lo que no se sabe:** cuántos productos nuevos entran al mes. Depende de
  cuántos catálogos y temporadas se importen. Por eso los escenarios
  mensuales son **supuestos**:
  - **30 productos al mes.** Toma el cupo de «30 productos» que propuso Kevin
    (propuesta, no aprobada).
  - **150 productos al mes.** Aproximadamente un catálogo del tamaño del
    actual cada mes.
  - **500 productos al mes.** Crecimiento con varias distribuidoras y líneas.

Otra cosa sin medir: si cada color cuenta como publicación aparte con sus
propias fotos, el número de imágenes sube. Los escenarios cuentan por
producto.

### Costo en pesos (con IVA, 2 imágenes por producto, 1.5 intentos)

| Modelo | Una vez: 154 actuales | 30 al mes (supuesto) | 150 al mes (supuesto) | 500 al mes (supuesto) |
|---|---|---|---|---|
| Nano Banana 2.1 Batch, 1024 px | $174.37 | $33.97 | $169.84 | $566.13 |
| Nano Banana 2.1 o Flash Lite Image, 1024 px | $348.74 | $67.94 | $339.68 | $1,132.26 |
| Nano Banana 2.1, 2048 px | $523.10 | $101.90 | $509.52 | $1,698.39 |
| OpenAI `gpt-image-2` media | $550.09 | $107.16 | $535.80 | $1,786.01 |
| Gemini 3.1 Flash Image, 1024 px | $695.40 | $135.47 | $677.33 | $2,257.78 |
| Nano Banana Pro | $1,402.21 | $273.16 | $1,365.79 | $4,552.63 |
| OpenAI `gpt-image-2` alta | $2,189.98 | $426.62 | $2,133.10 | $7,110.33 |

Para comparar: el cargo actual de Claude es **$362.26 al mes** (Análisis de
costos v7).

Guardar las imágenes en Cloudflare R2 cuesta 0.015 USD por GB al mes, con
10 GB gratis ([precios de R2](https://developers.cloudflare.com/r2/pricing/)).
Con fotos WebP de ese tamaño, incluso 500 productos al mes quedan muy lejos de
llenar un GB, así que no se suma aparte.

## 6. Impacto en el Análisis de costos

Como la importación de catálogos, la generación la haría el admin general
sobre el catálogo maestro, así que su gasto no depende de cuántas
distribuidoras haya. Siguiendo el criterio de la v7, va como **costo fijo**.

Datos del Análisis v7: costos fijos de **$6,684.70** al mes y margen de
contribución promedio de **$599.08** por distribuidora. Punto de equilibrio
actual: 12 distribuidoras. Para la meta de $50,000 antes de impuestos: 95.

| Escenario | Costo fijo nuevo | Punto de equilibrio | Para la meta de $50,000 |
|---|---|---|---|
| Hoy, sin opción B | $6,684.70 | 12 | 95 |
| Nano Banana 2.1, 30 al mes | $6,752.64 | 12 | 95 |
| Nano Banana 2.1, 150 al mes | $7,024.38 | 12 | 96 |
| Nano Banana 2.1, 500 al mes | $7,816.96 | 14 | 97 |
| `gpt-image-2` media, 150 al mes | $7,220.50 | 13 | 96 |
| `gpt-image-2` media, 500 al mes | $8,470.71 | 15 | 98 |
| Nano Banana Pro, 150 al mes | $8,050.49 | 14 | 97 |
| Nano Banana Pro, 500 al mes | $11,237.33 | 19 | 103 |
| `gpt-image-2` alta, 500 al mes | $13,795.03 | 24 | 107 |

Cálculo: punto de equilibrio = costos fijos ÷ $599.08, redondeado hacia
arriba; meta = (costos fijos + $50,000) ÷ $599.08, redondeado hacia arriba.

**Lectura:** con un modelo económico (Nano Banana 2.1 a 1024 px o
`gpt-image-2` en calidad media) y hasta unos 150 productos al mes, la opción B
casi no mueve el punto de equilibrio (12 → 12 o 13). Lo que sí lo mueve es
combinar un modelo caro con mucho volumen. El gasto de una sola vez para
completar los 154 productos actuales va de $174 a $2,190 según el modelo.

Cuando se apruebe la opción B, este número tiene que entrar en una **versión 8
del Análisis de costos**, con el modelo elegido y el volumen real.

## 7. Lo que hay que hacer antes de retomarla

1. **Prueba piloto con 10 productos reales** en 2 o 3 modelos (por ejemplo
   Nano Banana 2.1, `gpt-image-2` media y Nano Banana Pro). Medir: cuántas
   imágenes rechaza el admin (para cambiar el 1.5), el costo real con `usage`
   (incluida la foto de entrada) y si el zapato generado es fiel al real
   (color, suela, costuras, logotipo). A 0.0336–0.211 USD por imagen, la
   prueba cuesta centavos de dólar, pero necesita una cuenta con tarjeta, que
   no se abrió.
2. **Rótulo «imagen ilustrativa»** en toda foto generada, en la tienda, el
   marketplace y la app. Lo pide el plan.
3. **Revisión humana obligatoria** del admin general antes de publicar, como
   ya pasa con el recorte de la IA.
4. **Permiso de las marcas.** Una vista generada es una imagen derivada de la
   foto de la marca (punto 2-9 del plan). La carta de `docs/autorizaciones-de-marcas.md`
   pide permiso para mostrar sus fotos «tal como vienen en su catálogo»; no
   cubre imágenes generadas. Habría que pedirlo aparte o ampliar la carta.
5. **Tope de gasto propio** (por ejemplo `IA_MAX_USD_MES_IMAGENES`), igual que
   el que ya existe para leer catálogos.

## 8. Decisiones pendientes

Ninguna de estas se tomó aquí:

- **Proveedor y modelo:** depende de la prueba piloto. Por precio y porque
  edita a partir de una foto, Nano Banana 2.1 a 1024 px es el punto de partida
  más barato; `gpt-image-2` media es la alternativa más cercana en precio.
- **Cuántas vistas generar** (aquí se supuso 2) y si se cuentan por producto o
  por color.
- **Si se completa el catálogo actual** (gasto de una vez) o solo lo nuevo.
- **Presupuesto mensual** que se aprueba para esto.
- **Permiso de las marcas** para imágenes derivadas.

## 9. Riesgos

- **Los precios cambian seguido.** Google, por ejemplo, ya anunció que algunos
  modelos de texto duplican su precio el 01/12/2026. Hay que volver a
  consultar las fuentes antes de aprobar.
- **El tipo de cambio** mueve todo el cálculo en pesos.
- **El IVA del 16 %** es un supuesto; depende de cómo facture cada proveedor.
- **La foto de entrada** no está incluida y puede subir el costo, sobre todo
  en OpenAI, que procesa la entrada en alta fidelidad.
- **Fidelidad:** un modelo puede inventar detalles del zapato. Una foto que
  no corresponde al producto genera reclamos y devoluciones. Por eso el rótulo
  y la revisión humana no son opcionales.
