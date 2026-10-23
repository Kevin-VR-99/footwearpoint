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
    if (filtro.talla == null && filtro.disponibilidad == null) return true;

    // Se revisa variante por variante: un modelo con tallas disponibles y
    // otras bajo pedido debe salir en los dos filtros, y con talla elegida
    // cuenta la disponibilidad de esa talla, no la del modelo completo.
    return producto.variantes.any((v) {
      if (filtro.talla != null && v.talla != filtro.talla) return false;
      if (filtro.disponibilidad == 'disponible') return v.disponibilidad == Disponibilidad.disponible;
      if (filtro.disponibilidad == 'bajo_pedido') return v.disponibilidad == Disponibilidad.bajoPedido;
      return true;
    });
  }).toList();
}

List<String> opcionesDe(Iterable<String?> valores, {bool comoTallas = false}) {
  final unicos = {for (final valor in valores) if (valor != null && valor.isNotEmpty) valor};
  final lista = unicos.toList()..sort(comoTallas ? compararTallas : null);
  return lista;
}
