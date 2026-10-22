import 'producto_catalogo.dart';

/// Filtros del catálogo. Se aplican sobre la lista ya guardada, para no
/// volver a descargar el catálogo cada vez que se cambia uno.
class FiltroCatalogo {
  const FiltroCatalogo({this.marca, this.categoria, this.talla, this.disponibilidad});

  final String? marca;
  final String? categoria;
  final String? talla;

  /// null = todo, 'disponible' o 'bajo_pedido'.
  final String? disponibilidad;

  bool get activo => marca != null || categoria != null || talla != null || disponibilidad != null;
}

List<ProductoCatalogo> aplicarFiltroCatalogo(List<ProductoCatalogo> productos, FiltroCatalogo filtro) {
  return productos.where((producto) {
    if (filtro.marca != null && producto.marca?.nombre != filtro.marca) return false;
    if (filtro.categoria != null && producto.categoria?.nombre != filtro.categoria) return false;
    if (filtro.talla != null && !producto.variantes.any((v) => v.talla == filtro.talla)) return false;
    if (filtro.disponibilidad == 'disponible' && producto.mejorDisponibilidad != Disponibilidad.disponible) {
      return false;
    }
    if (filtro.disponibilidad == 'bajo_pedido' && producto.mejorDisponibilidad != Disponibilidad.bajoPedido) {
      return false;
    }
    return true;
  }).toList();
}

List<String> opcionesDe(Iterable<String?> valores) {
  final unicos = {for (final valor in valores) if (valor != null && valor.isNotEmpty) valor};
  final lista = unicos.toList()..sort();
  return lista;
}
