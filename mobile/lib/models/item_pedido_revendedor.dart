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

  /// Para guardar el carrito en el teléfono (TG-165).
  Map<String, dynamic> aJson() => {
    'producto': producto.aJson(),
    'variante_id': variante.varianteId,
    'cantidad': cantidad,
  };

  /// Lo contrario de [aJson]. Null si la variante ya no viene en el producto
  /// o la cantidad no sirve: esa línea se descarta.
  static ItemPedidoRevendedor? desdeJson(Map<String, dynamic> json) {
    final producto = ProductoCatalogo.desdeJson(json['producto'] as Map<String, dynamic>);
    final varianteId = json['variante_id'] as int;
    final cantidad = json['cantidad'] as int;

    if (cantidad <= 0) return null;

    for (final variante in producto.variantes) {
      if (variante.varianteId == varianteId) {
        return ItemPedidoRevendedor(producto: producto, variante: variante, cantidad: cantidad);
      }
    }
    return null;
  }
}
