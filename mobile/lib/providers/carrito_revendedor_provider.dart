import 'dart:convert';

import 'package:flutter/foundation.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';

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
///
/// TG-165: además se guarda en el teléfono, una llave por usuario, para que
/// no se pierda al cerrar la app. Al cerrar sesión se queda guardado: cuando
/// ese mismo usuario vuelve a entrar, lo recupera; otro usuario nunca lo ve.
class CarritoRevendedorProvider extends ChangeNotifier {
  CarritoRevendedorProvider({this._almacen = const FlutterSecureStorage()});

  final FlutterSecureStorage _almacen;

  final List<ItemPedidoRevendedor> _items = [];

  /// De quién es este carrito. Null si no hay sesión.
  int? _usuarioId;

  /// Mientras se lee lo guardado no se escribe nada, para no pisarlo con un
  /// carrito a medias.
  bool _cargando = false;

  /// Las escrituras van una tras otra, en el orden de los cambios.
  Future<void> _escritura = Future.value();

  List<ItemPedidoRevendedor> get items => List.unmodifiable(_items);
  bool get vacio => _items.isEmpty;

  /// Total de piezas, para el globito del ícono del carrito.
  int get totalPiezas => _items.fold(0, (suma, item) => suma + item.cantidad);

  double get total => _items.fold(0, (suma, item) => suma + item.subtotal);

  static String llaveDe(int usuarioId) => 'carrito_revendedor_$usuarioId';

  /// Lo llama main.dart cada vez que cambia la sesión. Si el carrito era de
  /// otra persona (o ya no hay nadie), se vacía y se carga el guardado del
  /// usuario nuevo.
  ///
  /// No avisa a los listeners a propósito: se llama mientras se reconstruye
  /// la app, y en ese momento las pantallas que usan el carrito ya se están
  /// cerrando (al cerrar sesión se regresa al login). Cuando termina de leer
  /// lo guardado, sí avisa.
  void usarSesion(int? usuarioId) {
    if (usuarioId == _usuarioId) return;

    _usuarioId = usuarioId;
    _items.clear();

    if (usuarioId != null) _cargar(usuarioId);
  }

  /// Para las pruebas: espera a que termine de leer y de guardar.
  @visibleForTesting
  Future<void> get listo async {
    while (_cargando) {
      await Future<void>.delayed(Duration.zero);
    }
    await _escritura;
  }

  /// Agrega una pieza de esa variante. Si ya estaba, sube su cantidad.
  void agregar(ProductoCatalogo producto, VarianteCatalogo variante) {
    final existente = _buscar(variante.varianteId);

    if (existente != null) {
      existente.cantidad++;
    } else {
      _items.add(ItemPedidoRevendedor(producto: producto, variante: variante));
    }

    _cambio();
  }

  /// Cambia la cantidad de una línea. Con 0 o menos, la quita del carrito.
  void cambiarCantidad(ItemPedidoRevendedor item, int cantidad) {
    if (cantidad <= 0) {
      quitar(item);
      return;
    }

    item.cantidad = cantidad;
    _cambio();
  }

  void quitar(ItemPedidoRevendedor item) {
    _items.remove(item);
    _cambio();
  }

  /// Después de enviar el pedido. También se borra lo guardado.
  void vaciar() {
    _items.clear();
    _cambio();
  }

  void _cambio() {
    notifyListeners();
    if (!_cargando) _guardar();
  }

  Future<void> _cargar(int usuarioId) async {
    _cargando = true;

    List<ItemPedidoRevendedor> guardados = const [];
    try {
      final texto = await _almacen.read(key: llaveDe(usuarioId));
      if (texto != null) {
        guardados = [
          for (final json in jsonDecode(texto) as List<dynamic>)
            ?ItemPedidoRevendedor.desdeJson(json as Map<String, dynamic>),
        ];
      }
    } catch (e) {
      // Algo dañado o sin acceso al almacén: se empieza con el carrito vacío
      // en vez de tronar la app.
      debugPrint('No se pudo leer el carrito guardado: $e');
    }

    _cargando = false;

    // Si mientras tanto cambió la sesión, esto ya no es de nadie.
    if (_usuarioId != usuarioId) return;

    // Lo que se haya agregado mientras se leía se queda, junto con lo guardado.
    for (final guardado in guardados.reversed) {
      final existente = _buscar(guardado.variante.varianteId);
      if (existente != null) {
        existente.cantidad += guardado.cantidad;
      } else {
        _items.insert(0, guardado);
      }
    }

    notifyListeners();
    _guardar();
  }

  void _guardar() {
    final usuarioId = _usuarioId;
    if (usuarioId == null) return;

    final llave = llaveDe(usuarioId);
    final texto = _items.isEmpty ? null : jsonEncode([for (final item in _items) item.aJson()]);

    _escritura = _escritura.then((_) async {
      try {
        if (texto == null) {
          await _almacen.delete(key: llave);
        } else {
          await _almacen.write(key: llave, value: texto);
        }
      } catch (e) {
        // Sin almacén el carrito sigue sirviendo en memoria, como antes.
        debugPrint('No se pudo guardar el carrito: $e');
      }
    });
  }

  ItemPedidoRevendedor? _buscar(int varianteId) {
    for (final item in _items) {
      if (item.variante.varianteId == varianteId) return item;
    }
    return null;
  }
}
