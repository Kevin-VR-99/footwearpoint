{{--
    TG-381 A21 — «Saltar al contenido».

    El primer elemento enfocable de la página. Quien navega con teclado (o con
    lector de pantalla) entra por arriba y tendría que pasar por todo el menú
    lateral —en el panel son más de veinte enlaces— antes de llegar a lo que
    vino a hacer, y eso en CADA pantalla.

    No se usa .sr-only: eso lo escondería también cuando recibe el foco, y
    entonces alguien estaría enfocando un enlace que no se ve. Se saca de la
    pantalla con position y se trae de vuelta en :focus-visible, que es lo que
    hace .fp-saltar en app.css.
--}}

<a href="#contenido" class="fp-saltar fp-btn fp-btn-primario">Saltar al contenido</a>
