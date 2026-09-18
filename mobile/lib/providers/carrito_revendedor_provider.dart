import 'package:flutter/foundation.dart';

import '../models/item_pedido_revendedor.dart';
import '../models/producto_catalogo.dart';

/// El carrito del pedido acumulado del revendedor (E9-01 / E9-03).
///
/// Antes era una lista global y estática dentro de la pantalla de detalle:
/// sobrevivía al cierre de sesión, así que otro revendedor que entraba en el
/// mismo teléfono veía (y podía enviar como suyos) los productos del anterior
/// (corrección TG-165).
///
/// Ahora vive ligado a la sesión: main.dart le avisa quién inició sesión con
/// [usarSesion], y cuando cambia el usuario o se cierra la sesión se vacía.
class CarritoRevendedorProvider extends ChangeNotifier {
  final List<ItemPedidoRevendedor> _items = [];

  /// De quién es este carrito. Null si no hay sesión.
  int? _usuarioId;

  List<ItemPedidoRevendedor> get items => List.unmodifiable(_items);
  bool get vacio => _items.isEmpty;

  /// Total de piezas, para el globito del ícono del carrito.
  int get totalPiezas => _items.fold(0, (suma, item) => suma + item.cantidad);

  double get total => _items.fold(0, (suma, item) => suma + item.subtotal);

  /// Lo llama main.dart cada vez que cambia la sesión. Si el carrito era de
  /// otra persona (o ya no hay nadie), se vacía.
  ///
  /// No avisa a los listeners a propósito: se llama mientras se reconstruye
  /// la app, y en ese momento las pantallas que usan el carrito ya se están
  /// cerrando (al cerrar sesión se regresa al login).
  void usarSesion(int? usuarioId) {
    if (usuarioId == _usuarioId) return;

    _usuarioId = usuarioId;
    _items.clear();
  }

  /// Agrega una pieza de esa variante. Si ya estaba, sube su cantidad.
  void agregar(ProductoCatalogo producto, VarianteCatalogo variante) {
    final existente = _buscar(variante.varianteId);

    if (existente != null) {
      existente.cantidad++;
    } else {
      _items.add(ItemPedidoRevendedor(producto: producto, variante: variante));
    }

    notifyListeners();
  }

  /// Cambia la cantidad de una línea. Con 0 o menos, la quita del carrito.
  void cambiarCantidad(ItemPedidoRevendedor item, int cantidad) {
    if (cantidad <= 0) {
      quitar(item);
      return;
    }

    item.cantidad = cantidad;
    notifyListeners();
  }

  void quitar(ItemPedidoRevendedor item) {
    _items.remove(item);
    notifyListeners();
  }

  /// Después de enviar el pedido.
  void vaciar() {
    _items.clear();
    notifyListeners();
  }

  ItemPedidoRevendedor? _buscar(int varianteId) {
    for (final item in _items) {
      if (item.variante.varianteId == varianteId) return item;
    }
    return null;
  }
}
