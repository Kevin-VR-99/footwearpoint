import 'producto_catalogo.dart';

/// Representa un producto con su variante seleccionada dentro del carrito del revendedor.
class ItemPedidoRevendedor {
  ItemPedidoRevendedor({
    required this.producto,
    required this.variante,
    this.cantidad = 1,
  });

  final ProductoCatalogo producto;
  final VarianteCatalogo variante;
  int cantidad;

  double get subtotal => (producto.precioMayorista ?? producto.precioMinoristaSugerido) * cantidad;
}