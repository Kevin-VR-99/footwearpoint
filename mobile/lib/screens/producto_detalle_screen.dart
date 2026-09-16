import 'package:flutter/material.dart';
import '../models/producto_catalogo.dart';
import '../models/item_pedido_revendedor.dart';
import 'crear_pedido_screen.dart';
import 'pedido_revendedor_screen.dart';

/// Detalle de un producto del catálogo (E4-05 / E8 / E9): fotos, precios y la
/// disponibilidad de cada variante (talla/color).
class ProductoDetalleScreen extends StatefulWidget {
  const ProductoDetalleScreen({super.key, required this.producto});

  final ProductoCatalogo producto;

  @override
  State<ProductoDetalleScreen> createState() => _ProductoDetalleScreenState();
}

class _ProductoDetalleScreenState extends State<ProductoDetalleScreen> {
  int _fotoActual = 0;
  VarianteCatalogo? _varianteSeleccionada;
  
  // Carrito global estático en memoria para que persista al navegar entre productos
  static final List<ItemPedidoRevendedor> _carritoGlobalRevendedor = [];

  void _seleccionarVariante(VarianteCatalogo variante) {
    if (variante.disponibilidad == Disponibilidad.noDisponible) return;
    
    setState(() {
      _varianteSeleccionada = variante;
    });
  }

  void _agregarAlPedidoRevendedor() {
    if (_varianteSeleccionada == null) return;

    setState(() {
      final index = _carritoGlobalRevendedor.indexWhere(
        (item) => item.variante.varianteId == _varianteSeleccionada!.varianteId,
      );

      if (index >= 0) {
        _carritoGlobalRevendedor[index].cantidad++;
      } else {
        _carritoGlobalRevendedor.add(
          ItemPedidoRevendedor(
            producto: widget.producto,
            variante: _varianteSeleccionada!,
          ),
        );
      }
    });

    // Sin SnackBars molestos: la confirmación ocurre visualmente actualizando el contador del carrito en la AppBar.
    ScaffoldMessenger.of(context).clearSnackBars();
  }

  @override
  Widget build(BuildContext context) {
    final producto = widget.producto;
    final tema = Theme.of(context);
    final imagenes = producto.imagenesOrdenadas;

    final subtitulo = [
      if (producto.marca != null) producto.marca!.nombre,
      'Modelo ${producto.modelo}',
    ].join(' · ');

    final esRevendedor = producto.precioMayorista != null;
    final totalItemsCarrito = _carritoGlobalRevendedor.fold<int>(0, (sum, i) => sum + i.cantidad);

    return Scaffold(
      appBar: AppBar(
        title: Text(producto.nombre),
        actions: [
          // El icono del carrito ahora siempre se muestra para revendedores si hay ítems acumulados
          if (esRevendedor)
            IconButton(
              icon: Badge(
                isLabelVisible: totalItemsCarrito > 0,
                label: Text('$totalItemsCarrito'),
                child: const Icon(Icons.shopping_cart),
              ),
              onPressed: () {
                Navigator.push(
                  context,
                  MaterialPageRoute(
                    builder: (_) => PedidoRevendedorScreen(itemsIniciales: _carritoGlobalRevendedor),
                  ),
                );
              },
            ),
        ],
      ),
      bottomNavigationBar: SafeArea(
        child: Padding(
          padding: const EdgeInsets.all(16.0),
          child: esRevendedor
              ? Row(
                  children: [
                    Expanded(
                      child: OutlinedButton.icon(
                        onPressed: _varianteSeleccionada == null ? null : _agregarAlPedidoRevendedor,
                        icon: const Icon(Icons.add_shopping_cart),
                        label: const Text('Agregar a pedido'),
                      ),
                    ),
                    const SizedBox(width: 12),
                    Expanded(
                      child: FilledButton(
                        onPressed: _varianteSeleccionada == null 
                            ? null 
                            : () {
                                Navigator.push(
                                  context,
                                  MaterialPageRoute(
                                    builder: (_) => CrearPedidoScreen(
                                      producto: producto,
                                      variante: _varianteSeleccionada!,
                                    ),
                                  ),
                                );
                              },
                        child: const Text('Pedido directo'),
                      ),
                    ),
                  ],
                )
              : FilledButton(
                  onPressed: _varianteSeleccionada == null 
                      ? null 
                      : () {
                          Navigator.push(
                            context,
                            MaterialPageRoute(
                              builder: (_) => CrearPedidoScreen(
                                producto: producto,
                                variante: _varianteSeleccionada!,
                              ),
                            ),
                          );
                        },
                  child: const Text('Hacer pedido'),
                ),
        ),
      ),
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
                      _GrupoColor(
                        color: grupo.key, 
                        variantes: grupo.value,
                        varianteSeleccionada: _varianteSeleccionada,
                        onSeleccionar: _seleccionarVariante,
                      ),
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
    final mayorista = producto.precioMayorista;
    final ganancia = mayorista != null ? producto.precioMinoristaSugerido - mayorista : null;

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
            if (mayorista != null) ...[
              const Divider(height: 24),
              _FilaPrecio(
                etiqueta: 'Precio mayorista',
                precio: mayorista,
                estilo: tema.textTheme.titleMedium?.copyWith(
                  color: tema.colorScheme.primary,
                  fontWeight: FontWeight.w600,
                ),
              ),
              const SizedBox(height: 12),
              Container(
                padding: const EdgeInsets.all(10),
                decoration: BoxDecoration(
                  color: tema.colorScheme.primaryContainer.withOpacity(0.4),
                  borderRadius: BorderRadius.circular(8),
                ),
                child: Row(
                  mainAxisAlignment: MainAxisAlignment.spaceBetween,
                  children: [
                    const Text('Ganancia estimada:', style: TextStyle(fontWeight: FontWeight.w500)),
                    Text(
                      formatoPrecio(ganancia!),
                      style: TextStyle(
                        fontWeight: FontWeight.bold,
                        color: tema.colorScheme.primary,
                      ),
                    ),
                  ],
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
  const _GrupoColor({
    required this.color, 
    required this.variantes,
    required this.varianteSeleccionada,
    required this.onSeleccionar,
  });

  final String color;
  final List<VarianteCatalogo> variantes;
  final VarianteCatalogo? varianteSeleccionada;
  final ValueChanged<VarianteCatalogo> onSeleccionar;

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
              for (final variante in variantes) 
                _Talla(
                  variante: variante,
                  seleccionada: variante == varianteSeleccionada,
                  onTap: () => onSeleccionar(variante),
                ),
            ],
          ),
        ],
      ),
    );
  }
}

class _Talla extends StatelessWidget {
  const _Talla({
    required this.variante,
    required this.seleccionada,
    required this.onTap,
  });

  final VarianteCatalogo variante;
  final bool seleccionada;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final estilo = EstiloDisponibilidad.de(variante.disponibilidad);
    final noDisponible = variante.disponibilidad == Disponibilidad.noDisponible;
    final tema = Theme.of(context);

    return Tooltip(
      message: 'Talla ${variante.talla}: ${variante.disponibilidad.etiqueta}',
      child: InkWell(
        onTap: noDisponible ? null : onTap,
        borderRadius: BorderRadius.circular(8),
        child: Container(
          constraints: const BoxConstraints(minWidth: 52),
          padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
          decoration: BoxDecoration(
            color: seleccionada ? tema.colorScheme.primaryContainer : estilo.fondo,
            border: Border.all(
              color: seleccionada ? tema.colorScheme.primary : estilo.borde,
              width: seleccionada ? 2 : 1,
            ),
            borderRadius: BorderRadius.circular(8),
          ),
          child: Text(
            variante.talla,
            textAlign: TextAlign.center,
            style: TextStyle(
              color: seleccionada ? tema.colorScheme.onPrimaryContainer : estilo.texto,
              fontWeight: FontWeight.w600,
              decoration: noDisponible ? TextDecoration.lineThrough : null,
            ),
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

String formatoPrecio(double precio) {
  final partes = precio.toStringAsFixed(2).split('.');
  final entero = partes[0].replaceAllMapped(
    RegExp(r'\B(?=(\d{3})+(?!\d))'),
    (_) => ',',
  );

  return '\$$entero.${partes[1]}';
}