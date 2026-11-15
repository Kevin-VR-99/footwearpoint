# Demo, preguntas de defensa y evidencias — catálogo, fotos, diseño y métricas

TG-405 (A42). Es la aportación de esta área a tres cosas que arma el equipo:
el guion de la demo (K16), el banco de preguntas de defensa (K29) y las
evidencias de cierre.

---

## 1. Filas para el guion de la demo

Cuatro bloques. El tiempo es de ensayo, no de reloj en la defensa.

### Bloque 1 — El catálogo se arregló de verdad (≈2 min)

| Qué se hace | Qué se dice |
|---|---|
| Crear un producto a mano en el catálogo maestro | «Antes, un producto creado así no aparecía en la tienda de la distribuidora» |
| Elegir una temporada en el selector | «Ahora solo deja elegir la temporada actual y la siguiente: antes se podía crear en una que ya terminó, y entonces el producto no se veía nunca» |
| Abrir «Tallas y colores» y agregar una | «Y esta es la segunda causa: un producto sin tallas no se puede pedir, así que la tienda no lo mostraba. Ahora se le ponen aquí mismo» |
| Abrir la tienda pública y encontrarlo | «Y ahí está» |

**Lo que hay que resistir:** la tentación de decir «arreglamos un bug». Eran
**dos causas distintas**, y las dos se reprodujeron antes de tocar nada.

### Bloque 2 — Las fotos se ven completas (≈1 min)

| Qué se hace | Qué se dice |
|---|---|
| Abrir la pantalla de revisión de un catálogo importado | «Las fotos las recorta la IA al leer el catálogo de la fábrica» |
| Señalar una miniatura | «Antes se recortaban al cuadro y se cortaba el zapato. Ahora se ve completo, aunque sobre espacio a los lados» |

**Si preguntan por qué todavía se ve un recorte imperfecto:** porque el
recorte lo decide la IA al importar, y mejorarlo es otra tarea que está
esperando aprobación. Mostrar la foto completa fue lo que sí se podía hacer
sin volver a pagar la importación.

### Bloque 3 — El diseño es un sistema, no pantallas sueltas (≈2 min)

| Qué se hace | Qué se dice |
|---|---|
| Mostrar dos pantallas distintas con el mismo campo y el mismo botón | «Los colores, los bordes y los espacios salen de un solo lugar; las pantallas no los repiten» |
| Provocar un error en un formulario | «El error se ve igual en todas, y el campo se marca en rojo solo» |
| Tocar Tab al entrar a una pantalla | «Aparece Saltar al contenido: quien navega con teclado no tiene que pasar por todo el menú» |
| Usar la búsqueda compacta | «Se expande al enfocarla, para no robarle ancho a la pantalla en celular» |

### Bloque 4 — Métricas y privacidad (≈3 min)

| Qué se hace | Qué se dice |
|---|---|
| Abrir Métricas del admin general | «Cuatro indicadores, y **cada uno dice debajo qué significa**» |
| Señalar los dos de dinero | «Ingresos de suscripción es lo que las distribuidoras le pagan a FootwearPoint. Ventas de las tiendas es lo que ellas le cobran a sus clientes. **Nunca se suman**, y la pantalla lo dice» |
| Señalar «Usuarios activos» | «Cuenta personas distintas. Quien entra por el panel y por la app el mismo día cuenta una vez» |
| Entrar a la tienda pública en una ventana nueva | «Y aquí está lo de privacidad: el mapa es de Google. No carga solo» |
| Presionar «Mostrar el mapa» | «Hasta que la persona lo permite, su navegador no se conecta con Google. Lo medimos: cero peticiones antes de aceptar» |

---

## 2. Preguntas de defensa

Ocho, con lo que hay que contestar. La tarjeta pide cuatro como mínimo; las
otras son margen por si el jurado se mete por otro lado.

**1. ¿Cómo saben que el alta manual quedó arreglada y no solo parchada?**

Porque las dos causas se reprodujeron **antes** de tocar el código, cada una
por separado, dentro de transacciones que se deshacían. Una era la temporada
ya terminada; la otra, el producto sin tallas. Después se midió en producción:
154 productos, 0 en temporada no vigente, 1 sin talla pedible. Ese 1 era un
producto de prueba creado el mismo día.

**2. ¿Por qué los ingresos de suscripción y las ventas de las tiendas no se suman en un total?**

Porque es dinero de bolsillos distintos. Los ingresos de suscripción son de
FootwearPoint; las ventas de tienda son de la distribuidora y nunca pasan por
FootwearPoint. Sumarlos daría un número que no responde ninguna pregunta y
haría parecer que la plataforma gana mucho más de lo que gana. La pantalla lo
dice con todas sus letras, y hay una prueba que falla si alguien los mezcla.

**3. El registro de actividad, ¿no es vigilar a los usuarios?**

No, y está hecho a propósito para que no pueda serlo. Se guarda una fila por
persona, día y canal, y nada más: **no se guarda la IP ni qué hizo**. Con esa
tabla solo se puede responder «cuánta gente distinta usó la plataforma», no
«qué hizo fulano». El detalle se borra a los 90 días; antes de borrarlo se
calculan los totales del mes, que se guardan 24 meses. Y los grupos de menos
de cinco personas no se muestran, para que un número chico no señale a nadie.

**4. ¿Por qué el banner de cookies lo decide el navegador y no el servidor?**

Porque la página de inicio se guarda en el navegador para que funcione sin
internet, y esa copia se le sirve igual a todo el mundo. Si el servidor
decidiera mostrar o esconder el banner según una cookie, la copia guardada le
enseñaría a una persona la decisión de otra. Por eso el HTML es idéntico para
todos y quien decide es el navegador. Hay una prueba que compara el HTML con
cookie y sin cookie y falla si alguien lo cambia.

**5. ¿Cómo comprobaron que el contraste cumple AA?**

Midiendo el color real de cada elemento, no confiando en el valor escrito.
El framework de estilos genera los colores en un formato (oklab) que al
leerlo como texto da números equivocados, así que cada color se pinta en un
lienzo de un píxel y se lee ese píxel. Sobre fondos semitransparentes además
hay que componer el color con el de atrás, o la cuenta sale mal.

**6. ¿Qué garantiza que una prueba suya de verdad detecta el error?**

Que se rompe el código a propósito y se comprueba que la prueba falla. En
este sprint esa comprobación **encontró una prueba propia que estaba mal**:
tenía un carácter invisible en una expresión regular y nunca podía coincidir,
así que pasaba aunque el error estuviera presente. Se corrigió y se volvió a
comprobar.

**7. Si no hay JavaScript, ¿el mapa no se ve nunca?**

No se ve, y es a propósito. Quien inserta el mapa es el propio JavaScript
cuando hay permiso; si no corre, no hay a quién conectar con Google. Es mejor
quedarse sin mapa que conectar a alguien con un tercero sin preguntarle. La
dirección escrita y el botón «Cómo llegar» siguen ahí, y ese botón es un
enlace: solo abre Google si la persona lo toca.

**8. ¿Por qué la regla del «plan más contratado» vive en una clase aparte?**

Porque la usan dos pantallas. Si cada una la calculara por su cuenta, podrían
terminar diciendo números distintos y nadie sabría cuál creer. La regla lleva
tres decisiones fáciles de perder al copiar: se cuentan distribuidoras
distintas y no suscripciones, no cuentan las de prueba, y los empates se
muestran juntos.

---

## 3. Evidencias

### Lo que ya está y solo hay que enlazar

| Área | Evidencia | Dónde |
|---|---|---|
| Catálogo | Alta manual corregida | PR del A14 |
| Catálogo | Medición en producción (154 / 0 / 1) | En la tarjeta del A15 |
| Catálogo | Logo o inicial de la línea | PR del A24 |
| Fotos | Fotos completas | PR del A16 |
| Diseño | Tokens y componentes base | PR del A18 |
| Diseño | Búsqueda compacta | PR del A19 |
| Diseño | Estados, formularios y modales | PR del A20 |
| Diseño | Contraste AA y teclado | PR del A21 |
| Métricas | Apartado e indicadores | PRs del A33 y A34 |
| Métricas | Registro de actividad | PR del A43 |
| Privacidad | Banner de cookies y mapa con permiso | PR del A38 |

### Capturas que faltan tomar

Son las que no se entienden sin ver la pantalla. Conviene tomarlas **en
producción**, no en local:

1. Catálogo maestro con el selector de temporada abierto, enseñando que solo
   salen la actual y la siguiente.
2. El modal de «Tallas y colores» con una talla agregada.
3. Una foto de producto antes y después, lado a lado.
4. La pantalla de Métricas completa, con las definiciones debajo de cada
   número y la nota de que el dinero no se suma.
5. La tienda pública con el hueco del mapa **sin** permiso, y con el mapa
   **ya** permitido.
6. El banner de cookies, y el pie con «Preferencias de cookies».
7. «Saltar al contenido» visible al tocar Tab.

### Número que vale la pena decir

La suite de pruebas del proyecto va en **más de 2 000 pruebas**. No es un
adorno: es lo que permite cambiar algo compartido, como el diseño de todos
los formularios, sin romper lo que ya funcionaba.
