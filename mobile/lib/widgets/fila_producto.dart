import 'package:flutter/material.dart';

import '../models/producto_catalogo.dart';
import '../screens/producto_detalle_screen.dart';
import '../tema/fp_colores.dart';
import 'bajo_pedido.dart';

/// Un producto en una lista, compacto (TG-281).
///
/// Antes cada producto ocupaba un cuarto de pantalla: la foto era de 110 px y
/// debajo del precio iban la etiqueta de disponibilidad y la de bajo pedido,
/// cada una en su propio renglón. Cabían tres productos y había que deslizar
/// mucho para comparar.
///
/// Aquí la foto baja a 84 px y las dos etiquetas se ponen una al lado de la
/// otra, así que caben el doble sin quitar ni un dato. La idea está tomada de
/// las listas de Mercado Libre, pero con los colores de la casa.
///
/// Se usa en los productos de una línea y en los resultados del buscador del
/// catálogo, para que un producto se vea igual en los dos sitios.
class FilaProducto extends StatelessWidget {
  const FilaProducto({super.key, required this.producto, this.alTocar});

  final ProductoCatalogo producto;

  /// Por omisión abre el detalle del producto.
  final VoidCallback? alTocar;

  @override
  Widget build(BuildContext context) {
    final disponibilidad = producto.mejorDisponibilidad;
    final subtitulo = [
      if (producto.marca != null) producto.marca!.nombre,
      producto.modelo,
    ].where((t) => t.isNotEmpty).join(' · ');

    return Card(
      margin: EdgeInsets.zero,
      clipBehavior: Clip.antiAlias,
      child: InkWell(
        onTap: alTocar ??
            () => Navigator.of(context).push(
              MaterialPageRoute<void>(
                builder: (_) => ProductoDetalleScreen(producto: producto),
              ),
            ),
        child: Padding(
          padding: const EdgeInsets.all(10),
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              ClipRRect(
                borderRadius: BorderRadius.circular(10),
                child: SizedBox(
                  width: 84,
                  height: 84,
                  child: ImagenProducto(url: producto.imagenPrincipal?.miniatura),
                ),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      producto.nombre,
                      maxLines: 2,
                      overflow: TextOverflow.ellipsis,
                      style: const TextStyle(
                        fontSize: 15,
                        height: 1.25,
                        fontWeight: FontWeight.w600,
                        color: FpColores.sidebar,
                      ),
                    ),
                    if (subtitulo.isNotEmpty) ...[
                      const SizedBox(height: 2),
                      Text(
                        subtitulo,
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: const TextStyle(fontSize: 12, color: FpColores.textoTenue),
                      ),
                    ],
                    const SizedBox(height: 6),
                    // Como en Mercado Libre: el precio que paga el cliente,
                    // grande y sin negrita de más; y debajo, en el azul de la
                    // marca, lo que paga el mayorista. Antes competían.
                    Text(
                      formatoPrecio(producto.precioMinoristaSugerido),
                      style: const TextStyle(
                        fontSize: 18,
                        height: 1.1,
                        fontWeight: FontWeight.w500,
                        color: FpColores.sidebar,
                      ),
                    ),
                    if (producto.precioMayorista != null)
                      Text(
                        'Mayorista: ${formatoPrecio(producto.precioMayorista!)}',
                        style: const TextStyle(
                          fontSize: 13,
                          fontWeight: FontWeight.w600,
                          color: FpColores.primario,
                        ),
                      ),
                    if (disponibilidad != null) ...[
                      const SizedBox(height: 6),
                      // Las dos etiquetas en el mismo renglón; si no caben,
                      // la segunda baja sola.
                      Wrap(
                        spacing: 6,
                        runSpacing: 4,
                        children: [
                          EtiquetaDisponibilidad(disponibilidad: disponibilidad),
                          // TG-266: con tallas disponibles la etiqueta dice
                          // "Disponible" y escondía las que van bajo pedido.
                          if (disponibilidad == Disponibilidad.disponible && producto.hayBajoPedido)
                            const InsigniaBajoPedido(texto: 'Algunas tallas bajo pedido'),
                        ],
                      ),
                    ],
                  ],
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
