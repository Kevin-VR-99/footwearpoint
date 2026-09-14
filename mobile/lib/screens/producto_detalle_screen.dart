import 'package:flutter/material.dart';

import '../models/producto_catalogo.dart';

/// Detalle de un producto del catálogo (E4-05): fotos, precios y la
/// disponibilidad de cada variante (talla/color).
///
/// Solo consulta. Agregar al pedido es parte de E8-01 / E9.
class ProductoDetalleScreen extends StatefulWidget {
  const ProductoDetalleScreen({super.key, required this.producto});

  final ProductoCatalogo producto;

  @override
  State<ProductoDetalleScreen> createState() => _ProductoDetalleScreenState();
}

class _ProductoDetalleScreenState extends State<ProductoDetalleScreen> {
  int _fotoActual = 0;

  @override
  Widget build(BuildContext context) {
    final producto = widget.producto;
    final tema = Theme.of(context);
    final imagenes = producto.imagenesOrdenadas;

    final subtitulo = [
      if (producto.marca != null) producto.marca!.nombre,
      'Modelo ${producto.modelo}',
    ].join(' · ');

    return Scaffold(
      appBar: AppBar(title: Text(producto.nombre)),
      body: SafeArea(
        child: ListView(
          children: [
            SizedBox(
              height: 280,
              child: imagenes.isEmpty
                  ? const ImagenProducto(url: null)
                  : Stack(
                      children: [
                        PageView.builder(
                          itemCount: imagenes.length,
                          onPageChanged: (i) => setState(() => _fotoActual = i),
                          itemBuilder: (_, i) => ImagenProducto(url: imagenes[i].url),
                        ),
                        if (imagenes.length > 1)
                          Positioned(
                            bottom: 8,
                            left: 0,
                            right: 0,
                            child: _Puntos(total: imagenes.length, actual: _fotoActual),
                          ),
                      ],
                    ),
            ),
            Padding(
              padding: const EdgeInsets.all(16),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    producto.nombre,
                    style: tema.textTheme.headlineSmall?.copyWith(fontWeight: FontWeight.bold),
                  ),
                  const SizedBox(height: 4),
                  Text(subtitulo, style: tema.textTheme.bodyMedium),
                  const SizedBox(height: 4),
                  Text(
                    [
                      if (producto.linea != null) 'Línea ${producto.linea!.nombre}',
                      if (producto.categoria != null) producto.categoria!.nombre,
                      'Código ${producto.codigoCatalogo}',
                    ].join(' · '),
                    style: tema.textTheme.bodySmall?.copyWith(
                      color: tema.colorScheme.onSurfaceVariant,
                    ),
                  ),
                  const SizedBox(height: 16),
                  _Precios(producto: producto),
                  const SizedBox(height: 24),
                  Text('Tallas y colores', style: tema.textTheme.titleMedium),
                  const SizedBox(height: 8),
                  const _Leyenda(),
                  const SizedBox(height: 16),
                  if (producto.variantes.isEmpty)
                    Text(
                      'Este producto todavía no tiene tallas ni colores registrados.',
                      style: tema.textTheme.bodyMedium,
                    )
                  else
                    for (final grupo in producto.variantesPorColor.entries)
                      _GrupoColor(color: grupo.key, variantes: grupo.value),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _Precios extends StatelessWidget {
  const _Precios({required this.producto});

  final ProductoCatalogo producto;

  @override
  Widget build(BuildContext context) {
    final tema = Theme.of(context);

    return Card(
      margin: EdgeInsets.zero,
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            _FilaPrecio(
              etiqueta: 'Precio sugerido de venta',
              precio: producto.precioMinoristaSugerido,
              estilo: tema.textTheme.titleLarge?.copyWith(fontWeight: FontWeight.bold),
            ),
            // Solo llega para revendedor: al cliente directo el servidor ni
            // siquiera le manda el precio mayorista.
            if (producto.precioMayorista != null) ...[
              const Divider(height: 24),
              _FilaPrecio(
                etiqueta: 'Precio mayorista',
                precio: producto.precioMayorista!,
                estilo: tema.textTheme.titleMedium?.copyWith(
                  color: tema.colorScheme.primary,
                  fontWeight: FontWeight.w600,
                ),
              ),
            ],
          ],
        ),
      ),
    );
  }
}

class _FilaPrecio extends StatelessWidget {
  const _FilaPrecio({required this.etiqueta, required this.precio, this.estilo});

  final String etiqueta;
  final double precio;
  final TextStyle? estilo;

  @override
  Widget build(BuildContext context) {
    return Row(
      children: [
        Expanded(child: Text(etiqueta)),
        Text(formatoPrecio(precio), style: estilo),
      ],
    );
  }
}

class _Leyenda extends StatelessWidget {
  const _Leyenda();

  @override
  Widget build(BuildContext context) {
    return Wrap(
      spacing: 8,
      runSpacing: 8,
      children: [
        for (final disponibilidad in Disponibilidad.values)
          EtiquetaDisponibilidad(disponibilidad: disponibilidad),
      ],
    );
  }
}

class _GrupoColor extends StatelessWidget {
  const _GrupoColor({required this.color, required this.variantes});

  final String color;
  final List<VarianteCatalogo> variantes;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 16),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(color, style: const TextStyle(fontWeight: FontWeight.w600)),
          const SizedBox(height: 8),
          Wrap(
            spacing: 8,
            runSpacing: 8,
            children: [
              for (final variante in variantes) _Talla(variante: variante),
            ],
          ),
        ],
      ),
    );
  }
}

/// Una talla, pintada según su disponibilidad.
class _Talla extends StatelessWidget {
  const _Talla({required this.variante});

  final VarianteCatalogo variante;

  @override
  Widget build(BuildContext context) {
    final estilo = EstiloDisponibilidad.de(variante.disponibilidad);
    final noDisponible = variante.disponibilidad == Disponibilidad.noDisponible;

    return Tooltip(
      message: 'Talla ${variante.talla}: ${variante.disponibilidad.etiqueta}',
      child: Container(
        constraints: const BoxConstraints(minWidth: 52),
        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
        decoration: BoxDecoration(
          color: estilo.fondo,
          border: Border.all(color: estilo.borde),
          borderRadius: BorderRadius.circular(8),
        ),
        child: Text(
          variante.talla,
          textAlign: TextAlign.center,
          style: TextStyle(
            color: estilo.texto,
            fontWeight: FontWeight.w600,
            decoration: noDisponible ? TextDecoration.lineThrough : null,
          ),
        ),
      ),
    );
  }
}

class _Puntos extends StatelessWidget {
  const _Puntos({required this.total, required this.actual});

  final int total;
  final int actual;

  @override
  Widget build(BuildContext context) {
    return Row(
      mainAxisAlignment: MainAxisAlignment.center,
      children: [
        for (var i = 0; i < total; i++)
          Container(
            width: 8,
            height: 8,
            margin: const EdgeInsets.symmetric(horizontal: 3),
            decoration: BoxDecoration(
              shape: BoxShape.circle,
              color: i == actual ? Colors.white : Colors.white54,
            ),
          ),
      ],
    );
  }
}

// ---------------------------------------------------------------------------
// Piezas que también usa la lista de productos (productos_linea_screen.dart).
// ---------------------------------------------------------------------------

/// Colores de cada disponibilidad, iguales en la lista y en el detalle.
class EstiloDisponibilidad {
  const EstiloDisponibilidad({required this.fondo, required this.borde, required this.texto});

  final Color fondo;
  final Color borde;
  final Color texto;

  static EstiloDisponibilidad de(Disponibilidad disponibilidad) {
    return switch (disponibilidad) {
      Disponibilidad.disponible => EstiloDisponibilidad(
        fondo: Colors.green.shade50,
        borde: Colors.green.shade400,
        texto: Colors.green.shade900,
      ),
      Disponibilidad.bajoPedido => EstiloDisponibilidad(
        fondo: Colors.orange.shade50,
        borde: Colors.orange.shade400,
        texto: Colors.orange.shade900,
      ),
      Disponibilidad.noDisponible => EstiloDisponibilidad(
        fondo: Colors.grey.shade100,
        borde: Colors.grey.shade400,
        texto: Colors.grey.shade600,
      ),
    };
  }
}

class EtiquetaDisponibilidad extends StatelessWidget {
  const EtiquetaDisponibilidad({super.key, required this.disponibilidad});

  final Disponibilidad disponibilidad;

  @override
  Widget build(BuildContext context) {
    final estilo = EstiloDisponibilidad.de(disponibilidad);

    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
      decoration: BoxDecoration(
        color: estilo.fondo,
        border: Border.all(color: estilo.borde),
        borderRadius: BorderRadius.circular(16),
      ),
      child: Text(
        disponibilidad.etiqueta,
        style: TextStyle(color: estilo.texto, fontSize: 12, fontWeight: FontWeight.w600),
      ),
    );
  }
}

/// La foto de un producto. Sin foto, o si no carga, un ícono en su lugar.
class ImagenProducto extends StatelessWidget {
  const ImagenProducto({super.key, required this.url});

  final String? url;

  @override
  Widget build(BuildContext context) {
    final colores = Theme.of(context).colorScheme;

    final sinFoto = ColoredBox(
      color: colores.surfaceContainerHighest,
      child: Center(
        child: Icon(Icons.image_not_supported_outlined, size: 40, color: colores.onSurfaceVariant),
      ),
    );

    if (url == null) return sinFoto;

    return Image.network(
      url!,
      fit: BoxFit.cover,
      width: double.infinity,
      errorBuilder: (_, _, _) => sinFoto,
      loadingBuilder: (_, hijo, progreso) =>
          progreso == null ? hijo : const Center(child: CircularProgressIndicator()),
    );
  }
}

/// 1600 -> "$1,600.00"
String formatoPrecio(double precio) {
  final partes = precio.toStringAsFixed(2).split('.');
  final entero = partes[0].replaceAllMapped(
    RegExp(r'\B(?=(\d{3})+(?!\d))'),
    (_) => ',',
  );

  return '\$$entero.${partes[1]}';
}
