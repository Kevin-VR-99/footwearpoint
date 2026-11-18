# Autorización de fábricas y marcas para fotos y logotipos

TG-404 (A41). El «listo cuando» de la tarjeta es **evidencia en Drive por cada
línea publicada**. Este documento dice a quién hay que pedírsela, qué se le
pide, con qué texto y dónde se guarda la respuesta.

No es la evidencia: la evidencia vive en Drive. Esto es para que cualquiera
del equipo pueda continuar el trámite sin preguntar.

---

## 1. A quién hay que pedirle

Se le pide a cada **línea publicada**, no a cada producto. Una línea está
publicada si tiene productos activos en alguna campaña, porque entonces sus
fotos ya se ven en el marketplace, en la tienda pública y en la app.

Para sacar la lista de producción, corre esto en el servicio `footwearpoint`
de Railway:

```bash
php artisan tinker --execute="foreach (DB::table('lineas as l')->leftJoin('campanas as c','c.linea_id','=','l.id')->leftJoin('producto_campana as pc','pc.campana_id','=','c.id')->leftJoin('productos as p','p.id','=','pc.producto_id')->leftJoin('marcas as m','m.id','=','p.marca_id')->where(function(\$q){\$q->whereNull('pc.activo')->orWhere('pc.activo',true);})->groupBy('l.id','l.nombre')->selectRaw('l.nombre, COUNT(DISTINCT pc.id) as productos, GROUP_CONCAT(DISTINCT m.nombre ORDER BY m.nombre SEPARATOR \", \") as marcas')->orderByDesc('productos')->get() as \$f) { echo \$f->nombre.' | '.\$f->productos.' productos | '.\$f->marcas.PHP_EOL; }"
```

Da una línea por fábrica, cuántos productos suyos están publicados y qué
marcas aparecen dentro de ellos. Esa es la lista de trámites.

### Dos cosas que hay que mirar en esa lista

**Una línea con 0 productos no necesita autorización todavía.** No se ve
nada suyo. Si más adelante se publican sus productos, vuelve a hacer falta.

**Una marca de talla mundial dentro de una línea es una señal de alto.** Si
aparecen marcas como Nike, Adidas o Puma, no se trata de pedir permiso: esas
empresas no autorizan proyectos escolares y no van a contestar. Lo que
corresponde es **despublicar esos productos antes de la defensa**, no esperar
una respuesta que no va a llegar. Despublicar no borra nada: se desactiva el
producto en la campaña y deja de verse.

---

## 2. Qué se le pide exactamente

Tres permisos, y conviene nombrarlos por separado para que la respuesta sirva
de evidencia:

1. **Las fotos de sus productos**, tal como vienen en su catálogo, para
   mostrarlas en la plataforma (marketplace, tienda pública de la
   distribuidora y app).
2. **Su logotipo**, para identificar la línea dentro del catálogo.
3. **Su nombre comercial**, para nombrar la línea y la marca.

No se pide exclusividad, ni cesión de derechos, ni nada que se parezca a
comprar la marca. Es permiso de uso para mostrar su producto, que es lo que
la distribuidora ya hace cuando lo vende.

---

## 3. Texto para pedirlo

### Versión corta (WhatsApp o correo de primer contacto)

> Buen día. Le escribo de parte de **[NOMBRE DE LA DISTRIBUIDORA]**, que
> distribuye calzado de **[LÍNEA]**.
>
> Estamos pasando el catálogo a una plataforma en línea llamada FootwearPoint,
> donde nuestros clientes ven los modelos y hacen sus pedidos. Es un proyecto
> escolar de titulación, sin fines de lucro.
>
> Para poder mostrar sus modelos necesitamos su autorización por escrito para
> usar **las fotos de su catálogo, su logotipo y el nombre de la marca**,
> únicamente dentro de la plataforma y para vender su producto.
>
> Con que nos conteste este mensaje diciendo que lo autoriza es suficiente.
> Si prefiere un documento formal, con gusto se lo mandamos para su firma.
>
> Quedo atento. Gracias.

### Versión formal (para firma)

> **[Lugar]**, a **[fecha]**.
>
> **[NOMBRE DE LA FÁBRICA O MARCA]**
> Presente.
>
> Por medio de la presente, **[NOMBRE Y CARGO DE QUIEN FIRMA]**, en
> representación de **[NOMBRE DE LA FÁBRICA O MARCA]**, autoriza a
> **[NOMBRE DE LA DISTRIBUIDORA]** y a la plataforma **FootwearPoint** a
> utilizar, sin costo y por tiempo indefinido:
>
> 1. Las **fotografías** de los productos de nuestro catálogo.
> 2. El **logotipo** de la marca.
> 3. El **nombre comercial** de la marca.
>
> El uso se limita a mostrar y comercializar nuestros productos dentro de la
> plataforma FootwearPoint, en su sitio web y en su aplicación móvil. No se
> autoriza ningún otro uso, ni se transfiere la propiedad de la marca ni de
> las fotografías, que siguen siendo de **[NOMBRE DE LA FÁBRICA O MARCA]**.
>
> Esta autorización puede revocarse en cualquier momento por escrito, y en ese
> caso el material se retirará de la plataforma.
>
> \_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_
> Nombre y firma

---

## 4. Dónde se guarda la evidencia

En Drive, una carpeta por línea:

```
FootwearPoint/
└── Legales/
    └── Autorizaciones de marcas/
        ├── Impuls/
        │   ├── autorizacion-impuls-2026-11-15.pdf   (o captura del WhatsApp)
        │   └── como-se-pidio.txt                     (fecha, medio, a quién)
        ├── Cklass/
        └── ...
```

Sirve como evidencia cualquiera de estas, siempre que se vea **quién la da y
qué autoriza**:

- Documento firmado (escaneado o foto).
- Correo de respuesta donde lo autoriza.
- Captura de la conversación de WhatsApp, con el número o el nombre visible.

Una respuesta de «sí, adelante» a la versión corta de arriba es suficiente,
porque el texto que contestan ya dice qué se está autorizando.

---

## 5. Seguimiento

Lleva esta tabla en la tarjeta de Taiga y actualízala cada vez que alguien
conteste. Las líneas salen del comando del punto 1.

| Línea | Productos publicados | A quién se le pidió | Fecha | Medio | Estado | Evidencia en Drive |
|---|---|---|---|---|---|---|
|  |  |  |  |  | Pendiente / Contestó / Autorizó / Negó |  |

**Si una línea no contesta antes del cierre de código (24 de noviembre),** no
se queda a medias: se despublican sus productos y se anota en la tarjeta que
se despublicaron por falta de autorización. Eso también es un resultado
defendible, y es mejor que publicar sin permiso.
