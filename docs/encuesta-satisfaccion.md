# Encuesta de satisfacción por rol y módulo

> TG-255 (R13) · Historia E18-04 "Aplicar encuesta de satisfacción".
> Meta de la historia: al menos 10 preguntas (Likert y abiertas) y al menos 15 respuestas.

**Formulario (Google Forms):** `[pegar aquí el link del formulario]`

## 1. Objetivo

Saber qué tan satisfechos están quienes usan FootwearPoint y **en qué módulo** hay que mejorar. Cada pregunta pertenece a un módulo, así que cada respuesta baja o cada comentario se puede llevar a una mejora concreta.

## 2. Reglas de la encuesta

- **Anónima.** No pide nombre, correo, teléfono ni distribuidora. En Google Forms: *no recopilar correos* y *no limitar a una respuesta* (ese límite exige iniciar sesión).
- **Pide el rol y los módulos que usa** (sección 0) para mandar a cada quien solo a las secciones que le tocan.
- **No responde el admin general** (equipo interno). Responden:

| Rol en el sistema | Se muestra en la encuesta como | Plataforma |
|---|---|---|
| `admin_distribuidora` | Administrador(a) de distribuidora | Web |
| `empleado` | Empleado(a) de distribuidora | Web |
| `revendedor` | Cliente mayorista | App |
| `cliente_directo` | Cliente | App |

- **Escala Likert de 1 a 5:** 1 = Totalmente en desacuerdo, 2 = En desacuerdo, 3 = Neutral, 4 = De acuerdo, 5 = Totalmente de acuerdo. Cada pregunta Likert tiene también la opción **"No lo uso"**, que no se cuenta en el promedio.
- Las preguntas abiertas son opcionales; las Likert, obligatorias.
- Duración estimada: de 5 a 10 minutos.

## 3. Estructura en Google Forms

| Sección | Quién la ve | Cómo se llega |
|---|---|---|
| 0. Perfil | Todos | Inicio |
| 1. General | Todos | Siempre |
| 2. Módulos web | Admin y empleado | "Ir a sección según la respuesta" de P0.1 |
| 3. Módulos app | Cliente mayorista y cliente | "Ir a sección según la respuesta" de P0.1 |
| 4. Cierre | Todos | Al final |

Dentro de las secciones 2 y 3, cada módulo es una subsección. P0.2 (casillas) dice qué módulos usa la persona; en Forms no se puede saltar por casillas, así que cada subsección de módulo empieza con "¿Usas este módulo?" (Sí / No) y el "No" salta al siguiente módulo.

## 4. Preguntas

### Sección 0 — Perfil (no se califican)

| ID | Pregunta | Tipo |
|---|---|---|
| P0.1 | ¿Cuál es tu rol? | Opción única: Administrador(a) de distribuidora / Empleado(a) / Cliente mayorista / Cliente |
| P0.2 | ¿Qué módulos usas? | Casillas con la lista de módulos de tu plataforma |
| P0.3 | ¿Desde hace cuánto usas FootwearPoint? | Opción única: menos de 1 mes / 1 a 3 meses / más de 3 meses |

### Sección 1 — General (todos los roles)

| ID | Pregunta | Tipo |
|---|---|---|
| G1 | En general, estoy satisfecho(a) con FootwearPoint. | Likert |
| G2 | Es fácil encontrar lo que necesito. | Likert |
| G3 | El sistema responde rápido. | Likert |
| G4 | Confío en los montos que muestra (precios, saldos, pagos, vales). | Likert |
| G5 | Del 0 al 10, ¿qué tan probable es que recomiendes FootwearPoint? | Escala 0–10 (NPS) |
| G6 | ¿Qué es lo que más te gusta del sistema? | Abierta |
| G7 | Si pudieras mejorar una sola cosa, ¿cuál sería? | Abierta |

### Sección 2 — Módulos web (administrador y empleado)

Cada módulo tiene dos Likert y una abierta. **W8 y W9 son solo para el administrador** (el empleado ve W8 únicamente si tiene el permiso de reportes financieros).

| Módulo | ID | Pregunta | Tipo |
|---|---|---|---|
| **W1. Catálogo y precios mayoristas** | W1.1 | Encuentro rápido los productos de la temporada. | Likert |
| | W1.2 | Fijar el precio para cliente mayorista y ocultar o mostrar productos es sencillo. | Likert |
| | W1.3 | ¿Qué le falta o te estorba en el catálogo? | Abierta |
| **W2. Stock** | W2.1 | El stock que muestra el sistema coincide con lo que tengo físicamente. | Likert |
| | W2.2 | Registrar entradas y ajustes de stock es sencillo. | Likert |
| | W2.3 | ¿Qué mejorarías del manejo de stock? | Abierta |
| **W3. Punto de venta** | W3.1 | Registrar una venta de mostrador es rápido. | Likert |
| | W3.2 | Aplicar un vale y cobrar el resto es claro. | Likert |
| | W3.3 | ¿Qué te frena al vender en mostrador? | Abierta |
| **W4. Pedidos y entregas** | W4.1 | Es claro en qué estado está cada pedido. | Likert |
| | W4.2 | Registrar anticipos, pagos y entregas es sencillo. | Likert |
| | W4.3 | ¿Qué mejorarías en pedidos y entregas? | Abierta |
| **W5. Ciclo de compra y solicitud a fábrica** | W5.1 | Entiendo cuándo abre y cierra el ciclo de compra. | Likert |
| | W5.2 | Armar y enviar la solicitud a fábrica es sencillo. | Likert |
| | W5.3 | ¿Qué cambiarías del ciclo o de la solicitud a fábrica? | Abierta |
| **W6. Vales** | W6.1 | Emitir y consultar vales es sencillo. | Likert |
| | W6.2 | El saldo y la vigencia de cada vale son claros. | Likert |
| | W6.3 | ¿Qué mejorarías de los vales? | Abierta |
| **W7. Cambios de producto** | W7.1 | Registrar una solicitud de cambio es sencillo. | Likert |
| | W7.2 | Es claro hasta cuándo puede pedir cambio un cliente. | Likert |
| | W7.3 | ¿Qué mejorarías de los cambios? | Abierta |
| **W8. Reportes** *(admin; empleado con permiso)* | W8.1 | Los reportes me ayudan a decidir (pedidos, pendientes, demanda). | Likert |
| | W8.2 | Los filtros (fechas, ciclo, temporada, marca) funcionan como espero. | Likert |
| | W8.3 | ¿Qué reporte o dato te hace falta? | Abierta |
| **W9. Configuración, permisos y usuarios** *(solo admin)* | W9.1 | Configurar plazos, permisos y datos de la distribuidora es sencillo. | Likert |
| | W9.2 | Dar de alta empleados y controlar lo que puede hacer cada uno es sencillo. | Likert |
| | W9.3 | ¿Qué te falta configurar? | Abierta |

### Sección 3 — Módulos de la app (cliente mayorista y cliente)

**A8 es solo para el cliente mayorista.**

| Módulo | ID | Pregunta | Tipo |
|---|---|---|---|
| **A1. Acceso y cuenta** | A1.1 | Entrar a la app y recuperar mi contraseña es sencillo. | Likert |
| | A1.2 | Mis datos de perfil son fáciles de consultar y cambiar. | Likert |
| | A1.3 | ¿Tuviste algún problema para entrar o con tu cuenta? | Abierta |
| **A2. Catálogo** | A2.1 | Encuentro fácilmente los productos que busco. | Likert |
| | A2.2 | Las fotos, tallas, colores y precios son claros. | Likert |
| | A2.3 | ¿Qué le falta al catálogo de la app? | Abierta |
| **A3. Hacer un pedido** | A3.1 | Hacer un pedido desde la app es rápido. | Likert |
| | A3.2 | Antes de enviarlo, entiendo cuánto debo pagar y de anticipo. | Likert |
| | A3.3 | ¿Qué te complica al hacer un pedido? | Abierta |
| **A4. Mis pedidos y pagos** | A4.1 | Es claro en qué estado está mi pedido. | Likert |
| | A4.2 | Pagar con Mercado Pago o ver mis pagos es sencillo. | Likert |
| | A4.3 | ¿Qué mejorarías en el seguimiento o el pago de tus pedidos? | Abierta |
| **A5. Vales** | A5.1 | Veo con claridad el saldo y la vigencia de mis vales. | Likert |
| | A5.2 | Usar un vale en un pedido es sencillo. | Likert |
| | A5.3 | ¿Qué mejorarías de los vales? | Abierta |
| **A6. Cambios de producto** | A6.1 | Pedir el cambio de un producto es sencillo. | Likert |
| | A6.2 | Es claro hasta cuándo puedo pedir el cambio y en qué va mi solicitud. | Likert |
| | A6.3 | ¿Qué mejorarías de los cambios? | Abierta |
| **A7. Notificaciones** | A7.1 | Las notificaciones me avisan a tiempo (pedido, pagos, vales). | Likert |
| | A7.2 | Las notificaciones son útiles y no son demasiadas. | Likert |
| | A7.3 | ¿Qué aviso te gustaría recibir o quitar? | Abierta |
| **A8. Mis clientes** *(solo cliente mayorista)* | A8.1 | Registrar y consultar a mis clientes es sencillo. | Likert |
| | A8.2 | Hacer pedidos para mis clientes es sencillo. | Likert |
| | A8.3 | ¿Qué te falta para manejar a tus clientes? | Abierta |

### Sección 4 — Cierre (todos)

| ID | Pregunta | Tipo |
|---|---|---|
| F1 | ¿Algo más que quieras contarnos? | Abierta |

## 5. Número de preguntas por rol

Sin contar la sección 0 (perfil). "Mínimo" es si la persona solo usa un módulo; "máximo", si usa todos los de su rol. Las preguntas "¿Usas este módulo?" no se cuentan.

| Rol | General + cierre | Módulos posibles | Máximo (Likert / abiertas) | Mínimo (Likert / abiertas) |
|---|---|---|---|---|
| Administrador(a) de distribuidora | 8 | W1–W9 (9) | **35** (23 / 12) | 11 (7 / 4) |
| Empleado(a) | 8 | W1–W7 (7), W8 con permiso | **29** (19 / 10); 32 con W8 | 11 (7 / 4) |
| Cliente mayorista | 8 | A1–A8 (8) | **32** (21 / 11) | 11 (7 / 4) |
| Cliente | 8 | A1–A7 (7) | **29** (19 / 10) | 11 (7 / 4) |

Todos los roles cumplen las 10 preguntas como mínimo, con Likert y abiertas (G5 se cuenta como Likert por ser escala).

## 6. Meta de respuestas

Al menos **15 respuestas**, repartidas para que haya opinión de cada rol:

| Rol | Mínimo |
|---|---|
| Administrador(a) de distribuidora | 3 |
| Empleado(a) | 3 |
| Cliente mayorista | 4 |
| Cliente | 5 |

## 7. Análisis

1. **Promedio por módulo y rol** de las Likert, sin contar "No lo uso".
2. **Señal de mejora:** un módulo pasa a la lista de mejoras si su promedio es **menor a 3.5** o si **más del 30 %** de las respuestas son 1 o 2.
3. **NPS (G5):** % de promotores (9–10) menos % de detractores (0–6).
4. **Abiertas:** se agrupan por módulo y tema (error, falta función, confuso, lento) y se cuenta cuántas veces sale cada tema.
5. Cada mejora detectada se registra en Taiga con el ID de la pregunta de donde salió (por ejemplo, "W3.2: aplicar vale en caja confuso").

### Plantilla de resultados

| Módulo | Rol | Respuestas | Promedio | % de 1–2 | ¿Mejora? | Temas de las abiertas |
|---|---|---|---|---|---|---|
| W1 | Admin | | | | | |
| … | | | | | | |

## 8. Fuera de alcance

- Integrar la encuesta en el panel o en la app (no hay tablas, pantallas ni endpoints).
- Crear el formulario en Google Forms, juntar las 15 respuestas y analizarlas: eso es parte de E18-04.
