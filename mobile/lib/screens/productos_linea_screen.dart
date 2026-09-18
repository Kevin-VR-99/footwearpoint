import 'package:flutter/material.dart';

import '../models/producto_catalogo.dart';
import '../tema/fp_colores.dart';
import '../widgets/fp_componentes.dart';
import 'producto_detalle_screen.dart';

/// Los productos de una línea del catálogo (E4-05).
class ProductosLineaScreen extends StatelessWidget {
  const ProductosLineaScreen({super.key, required this.linea});

  final LineaCatalogo linea;

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: Text(linea.nombre), bottom: const FpBordeMarca()),
      body: SafeArea(
        child: ListView.separated(
          padding: const EdgeInsets.all(16),
          itemCount: linea.productos.length,
          separatorBuilder: (_, _) => const SizedBox(height: 12),
          itemBuilder: (_, i) => _TarjetaProducto(producto: linea.productos[i]),
        ),
      ),
    );
  }
}

class _TarjetaProducto extends StatelessWidget {
  const _TarjetaProducto({required this.producto});

  final ProductoCatalogo producto;

  @override
  Widget build(BuildContext context) {
    final tema = Theme.of(context);
    final disponibilidad = producto.mejorDisponibilidad;

    return Card(
      margin: EdgeInsets.zero,
      clipBehavior: Clip.antiAlias,
      child: InkWell(
        onTap: () => Navigator.of(context).push(
          MaterialPageRoute<void>(
            builder: (_) => ProductoDetalleScreen(producto: producto),
          ),
        ),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            SizedBox(
              width: 110,
              height: 110,
              child: ImagenProducto(url: producto.imagenPrincipal?.url),
            ),
            Expanded(
              child: Padding(
                padding: const EdgeInsets.all(12),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      producto.nombre,
                      maxLines: 2,
                      overflow: TextOverflow.ellipsis,
                      style: tema.textTheme.titleMedium?.copyWith(
                        fontWeight: FontWeight.w600,
                        color: FpColores.sidebar,
                      ),
                    ),
                    const SizedBox(height: 2),
                    Text(
                      [
                        if (producto.marca != null) producto.marca!.nombre,
                        producto.modelo,
                      ].join(' · '),
                      style: tema.textTheme.bodySmall?.copyWith(
                        color: tema.colorScheme.onSurfaceVariant,
                      ),
                    ),
                    const SizedBox(height: 8),
                    Text(
                      formatoPrecio(producto.precioMinoristaSugerido),
                      style: tema.textTheme.titleMedium?.copyWith(
                        fontWeight: FontWeight.bold,
                        color: FpColores.sidebar,
                      ),
                    ),
                    if (producto.precioMayorista != null)
                      Text(
                        'Mayorista: ${formatoPrecio(producto.precioMayorista!)}',
                        style: tema.textTheme.bodySmall?.copyWith(
                          color: tema.colorScheme.primary,
                          fontWeight: FontWeight.w600,
                        ),
                      ),
                    if (disponibilidad != null) ...[
                      const SizedBox(height: 8),
                      EtiquetaDisponibilidad(disponibilidad: disponibilidad),
                    ],
                  ],
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }
}
