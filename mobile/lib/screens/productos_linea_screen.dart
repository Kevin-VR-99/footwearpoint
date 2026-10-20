import 'package:flutter/material.dart';

import '../models/producto_catalogo.dart';
import '../widgets/fila_producto.dart';
import '../widgets/fp_componentes.dart';

/// Los productos de una línea del catálogo (E4-05).
///
/// TG-281: con buscador. Una línea puede traer decenas de modelos y antes
/// había que recorrerlos todos con el dedo para encontrar uno.
class ProductosLineaScreen extends StatefulWidget {
  const ProductosLineaScreen({super.key, required this.linea});

  final LineaCatalogo linea;

  @override
  State<ProductosLineaScreen> createState() => _ProductosLineaScreenState();
}

class _ProductosLineaScreenState extends State<ProductosLineaScreen> {
  String _busqueda = '';

  @override
  Widget build(BuildContext context) {
    final productos = filtrarProductos(widget.linea.productos, _busqueda);

    return Scaffold(
      appBar: AppBar(title: Text(widget.linea.nombre), bottom: const FpBordeMarca()),
      body: SafeArea(
        child: Column(
          children: [
            Padding(
              padding: const EdgeInsets.fromLTRB(16, 12, 16, 8),
              child: FpBuscador(
                texto: 'Buscar por nombre, marca o modelo',
                alCambiar: (valor) => setState(() => _busqueda = valor),
              ),
            ),
            Expanded(
              child: productos.isEmpty
                  ? ListView(
                      children: [FpSinResultados(busqueda: _busqueda, que: 'productos')],
                    )
                  : ListView.separated(
                      padding: const EdgeInsets.fromLTRB(16, 8, 16, 16),
                      itemCount: productos.length,
                      separatorBuilder: (_, _) => const SizedBox(height: 10),
                      itemBuilder: (_, i) => FilaProducto(producto: productos[i]),
                    ),
            ),
          ],
        ),
      ),
    );
  }
}

/// Filtra por nombre, marca o modelo, sin distinguir mayúsculas.
///
/// Se busca por varias palabras sueltas y todas tienen que aparecer en algún
/// lado: así "adidas negro" encuentra el producto aunque la marca y el color
/// estén en campos distintos.
List<ProductoCatalogo> filtrarProductos(List<ProductoCatalogo> productos, String busqueda) {
  final palabras = busqueda.toLowerCase().trim().split(RegExp(r'\s+'))
      ..removeWhere((p) => p.isEmpty);

  if (palabras.isEmpty) return productos;

  return productos.where((producto) {
    final texto = [
      producto.nombre,
      producto.modelo,
      producto.marca?.nombre ?? '',
      producto.linea?.nombre ?? '',
      producto.codigoCatalogo,
    ].join(' ').toLowerCase();

    return palabras.every(texto.contains);
  }).toList();
}
