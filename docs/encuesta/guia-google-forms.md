# Guía: capturar la encuesta en Google Forms y exportar el CSV

> TG-256 (R14). Las preguntas salen de [`docs/encuesta-satisfaccion.md`](../encuesta-satisfaccion.md) (TG-255).
> El script de análisis (`analisis/analizar_encuesta.py`) depende de que **cada título empiece con el ID de la pregunta** y de que las opciones se escriban **exactamente** como aquí.

## 1. Crear el formulario

1. Entra a <https://forms.google.com> con la cuenta del equipo → **En blanco**.
2. Título: `Encuesta de satisfacción FootwearPoint`.
   Descripción: `Encuesta anónima, de 5 a 10 minutos. Nos ayuda a mejorar el sistema. No pedimos tu nombre ni tu correo.`
3. **Configuración** (engrane):
   - *Respuestas* → **Recopilar direcciones de correo: No recopilar**.
   - *Respuestas* → **Limitar a 1 respuesta: desactivado** (si se activa, Google pide iniciar sesión y deja de ser anónima).
   - *Presentación* → mensaje de confirmación: `¡Gracias! Tu respuesta es anónima.`
4. Pega el link (**Enviar** → pestaña del enlace → *Acortar URL*) en `docs/encuesta-satisfaccion.md`, en el espacio `[pegar aquí el link del formulario]`.

## 2. Convenciones (importantes para el script)

| Qué | Cómo capturarlo en Forms |
|---|---|
| **Título de cada pregunta** | ID + punto + espacio + texto. Ejemplo: `G1. En general, estoy satisfecho(a) con FootwearPoint.` |
| **Likert (1–5 con "No lo uso")** | Tipo **Opción múltiple**, obligatoria, con estas 6 opciones literales:<br>`1 - Totalmente en desacuerdo`<br>`2 - En desacuerdo`<br>`3 - Neutral`<br>`4 - De acuerdo`<br>`5 - Totalmente de acuerdo`<br>`No lo uso` |
| **NPS (G5)** | Tipo **Escala lineal** de **0 a 10**, obligatoria. Etiquetas: 0 = `Nada probable`, 10 = `Muy probable`. |
| **Abiertas** | Tipo **Párrafo**, opcional. |
| **"¿Usas este módulo?"** | Tipo **Opción múltiple**, obligatoria, título `W1.0 ¿Usas este módulo? (Catálogo y precios mayoristas)` (el ID del módulo + `.0`), opciones `Sí` / `No`. |

Tip: arma una pregunta Likert completa y usa **Duplicar** (ícono de copiar) para las demás; solo cambias el título.

## 3. Secciones y orden

Las secciones se agregan con el ícono **Agregar sección** (dos rectángulos) de la barra lateral. Forms solo puede saltar **al terminar una sección**, por eso:

- el rol (`P0.1`) va en su propia sección **después de General**: así General se captura una sola vez y el rol decide si siguen los módulos web o los de la app;
- cada módulo ocupa **dos secciones**: una solo con la pregunta "¿lo usas?" (`Xn.0`) y otra con sus preguntas (`Xn.1`–`Xn.3`).

| # | Sección | Preguntas |
|---|---|---|
| 1 | Perfil | P0.3, P0.2 |
| 2 | General | G1–G7 |
| 3 | Rol | P0.1 (decide la plataforma) |
| 4–21 | Web · W1 … W9 | por módulo: `Wn.0` · después `Wn.1`–`Wn.3` |
| 22–37 | App · A1 … A8 | por módulo: `An.0` · después `An.1`–`An.3` |
| 38 | Cierre | F1 |

> En el documento de la encuesta P0.1 aparece primero; en Forms va después de General solo por la forma en que Forms salta. Las preguntas no cambian.

### Perfil y rol

| ID y título | Tipo | Opciones |
|---|---|---|
| `P0.3 ¿Desde hace cuánto usas FootwearPoint?` | Opción múltiple, obligatoria | `Menos de 1 mes` · `1 a 3 meses` · `Más de 3 meses` |
| `P0.2 ¿Qué módulos usas?` | Casillas, opcional | Los 17 módulos con su ID: `W1. Catálogo y precios mayoristas` … `W9. Configuración, permisos y usuarios`, `A1. Acceso y cuenta` … `A8. Mis clientes` |
| `P0.1 ¿Cuál es tu rol?` | Opción múltiple, obligatoria (sección *Rol*) | `Administrador(a) de distribuidora` · `Empleado(a) de distribuidora` · `Cliente mayorista` · `Cliente` |

### General

`G1`–`G4` Likert, `G5` escala 0–10, `G6` y `G7` párrafo. Textos tal cual del documento de la encuesta.

### Módulos

Para cada módulo de la sección 4 de `docs/encuesta-satisfaccion.md`: sección con `Xn.0` (¿lo usas?) y luego sección con `Xn.1` y `Xn.2` Likert y `Xn.3` párrafo. En `W9.0` agrega `(solo administrador)` y en `A8.0` `(solo cliente mayorista)`.

### Cierre

`F1. ¿Algo más que quieras contarnos?` párrafo.

## 4. Saltos

**Por respuesta**: en la pregunta, menú ⋮ → **Ir a la sección según la respuesta**.

| Pregunta | Respuesta → sección |
|---|---|
| P0.1 | `Administrador(a) de distribuidora`, `Empleado(a) de distribuidora` → **W1.0** · `Cliente mayorista`, `Cliente` → **A1.0** |
| Wn.0 | `Sí` → preguntas de Wn · `No` → **W(n+1).0** (en W9.0: `No` → **Cierre**) |
| An.0 | `Sí` → preguntas de An · `No` → **A(n+1).0** (en A8.0: `No` → **Cierre**) |

**Al pie de la sección** ("Después de la sección N"):

| Sección | Ir a |
|---|---|
| Preguntas de Wn | W(n+1).0; las de **W9 → Cierre** (si no, el admin seguiría a los módulos de la app) |
| Preguntas de An | A(n+1).0; las de A8 → Cierre |

Flujo:

```
Perfil → General → Rol (P0.1)
  ├─ Admin / Empleado            → W1.0 → [W1.1–W1.3] → … → W9.0 → [W9.1–W9.3] → Cierre
  └─ Cliente mayorista / Cliente → A1.0 → [A1.1–A1.3] → … → A8.0 → [A8.1–A8.3] → Cierre
```

Notas:
- **W8** (reportes): el empleado sin el permiso contesta `No` en `W8.0`.
- **W9** es solo del administrador y **A8** solo del cliente mayorista. Si otro rol los contesta, el script los ignora (y lo avisa).

## 5. Probar antes de enviar

1. **Vista previa** (ícono del ojo) y contesta 4 veces, una por rol, revisando que los saltos lleven a donde deben.
2. Exporta el CSV (paso 6) y corre el script con él (ver `analisis/README.md`).
3. **Borra esas respuestas de prueba**: pestaña *Respuestas* → ⋮ → **Eliminar todas las respuestas**.

## 6. Exportar el CSV

1. Pestaña **Respuestas** → **Vincular con Hojas de cálculo** (ícono verde) → *Crear una hoja de cálculo nueva*.
2. En la hoja: **Archivo → Descargar → Valores separados por comas (.csv)**.
   (Alternativa sin hoja: pestaña *Respuestas* → ⋮ → **Descargar respuestas (.csv)**, que baja un .zip con el CSV.)
3. Guárdalo **fuera del repositorio** o en `docs/encuesta/analisis/datos/` (esa carpeta está ignorada por git). Aunque la encuesta es anónima, las respuestas abiertas pueden traer datos personales: **no se suben al repo**.
4. No cambies los encabezados de las columnas: el script reconoce cada pregunta por el ID al inicio del título.
