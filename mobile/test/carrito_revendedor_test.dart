import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:footwearpoint/main.dart';
import 'package:footwearpoint/models/producto_catalogo.dart';
import 'package:footwearpoint/providers/carrito_revendedor_provider.dart';
import 'package:footwearpoint/services/api_service.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:provider/provider.dart';

/// Un producto del catálogo con dos tallas (variantes 9 y 10).
ProductoCatalogo _producto() => ProductoCatalogo.desdeJson({
  'id': 12,
  'producto': {
    'id': 4,
    'modelo': 'MOD-01',
    'nombre': 'Botín casual',
    'marca': {'id': 1, 'nombre': 'Flexi'},
    'linea': {'id': 2, 'nombre': 'Dama'},
    'categoria': {'id': 3, 'nombre': 'Botines'},
  },
  'codigo_catalogo': 'CAT-001',
  'precio_minorista_sugerido': 800,
  'precio_mayorista': 500,
  'imagenes': <Object>[],
  'variantes': [
    {'variante_id': 9, 'sku': 'S-9', 'talla': '24', 'color': 'Negro', 'nombre_color_comercial': null, 'disponibilidad': 'disponible'},
    {'variante_id': 10, 'sku': 'S-10', 'talla': '25', 'color': 'Negro', 'nombre_color_comercial': null, 'disponibilidad': 'disponible'},
  ],
});

http.Response _json(Object cuerpo, int codigo) => http.Response(
  jsonEncode(cuerpo),
  codigo,
  headers: {'content-type': 'application/json; charset=utf-8'},
);

Map<String, dynamic> _sesion(int id, String nombre, String email) => {
  'usuario': {'id': id, 'nombre': nombre, 'email': email, 'telefono': null, 'estado': 'activo'},
  'rol': 'revendedor',
  'distribuidora_id': 1,
};

void main() {
  group('el carrito', () {
    test('agregar la misma talla sube la cantidad; otra talla es otra línea', () {
      final carrito = CarritoRevendedorProvider()..usarSesion(7);
      final producto = _producto();

      carrito.agregar(producto, producto.variantes[0]);
      carrito.agregar(producto, producto.variantes[0]);
      carrito.agregar(producto, producto.variantes[1]);

      expect(carrito.items, hasLength(2));
      expect(carrito.items.first.cantidad, 2);
      expect(carrito.totalPiezas, 3);
      // Precio mayorista: 3 piezas × $500.
      expect(carrito.total, 1500);
    });

    test('se puede quitar un producto, y bajar la cantidad a 0 también lo quita', () {
      final carrito = CarritoRevendedorProvider()..usarSesion(7);
      final producto = _producto();

      carrito.agregar(producto, producto.variantes[0]);
      carrito.agregar(producto, producto.variantes[1]);

      carrito.quitar(carrito.items.first);
      expect(carrito.items.single.variante.varianteId, 10);

      carrito.cambiarCantidad(carrito.items.single, 0);
      expect(carrito.vacio, isTrue);
    });

    test('si cambia el usuario o se cierra la sesión, se vacía', () {
      final carrito = CarritoRevendedorProvider()..usarSesion(7);
      final producto = _producto();

      carrito.agregar(producto, producto.variantes[0]);

      // El mismo usuario (por ejemplo, al reconstruirse la app) no lo vacía.
      carrito.usarSesion(7);
      expect(carrito.vacio, isFalse);

      // Sin sesión: se vacía.
      carrito.usarSesion(null);
      expect(carrito.vacio, isTrue);

      carrito.usarSesion(7);
      carrito.agregar(producto, producto.variantes[0]);

      // Otro revendedor: se vacía.
      carrito.usarSesion(9);
      expect(carrito.vacio, isTrue);
    });
  });

  /// El error de la prueba manual 3.7: otro revendedor en el mismo teléfono
  /// veía el carrito del anterior.
  testWidgets('otro revendedor que entra en el mismo teléfono no ve el carrito del anterior', (tester) async {
    FlutterSecureStorage.setMockInitialValues(<String, String>{'token_sanctum': '1|token-maria'});

    final servidor = MockClient((peticion) async {
      final ruta = peticion.url.path;

      if (ruta.endsWith('/auth/me')) {
        return _json({'data': _sesion(7, 'María López', 'maria@ejemplo.com')}, 200);
      }
      if (ruta.endsWith('/auth/login')) {
        return _json({
          'data': {'token': '2|token-roberto', 'token_type': 'Bearer', ..._sesion(9, 'Roberto García', 'roberto@ejemplo.com')},
        }, 200);
      }
      return _json({'message': 'ok'}, 200);
    });

    await tester.pumpWidget(FootwearPointApp(api: ApiService(cliente: servidor)));
    await tester.pumpAndSettle();

    CarritoRevendedorProvider carrito() =>
        Provider.of<CarritoRevendedorProvider>(tester.element(find.byType(Scaffold).first), listen: false);

    // María agrega un producto.
    final producto = _producto();
    carrito().agregar(producto, producto.variantes[0]);
    expect(carrito().totalPiezas, 1);

    // Cierra sesión y entra Roberto en el mismo teléfono.
    // En pantallas chicas el inicio se desliza: primero se lleva el botón a la vista.
    await tester.ensureVisible(find.text('Cerrar sesión'));
    await tester.tap(find.text('Cerrar sesión'));
    await tester.pumpAndSettle();

    await tester.enterText(find.widgetWithText(TextFormField, 'Correo'), 'roberto@ejemplo.com');
    await tester.enterText(find.widgetWithText(TextFormField, 'Contraseña'), 'password');
    await tester.tap(find.text('Entrar'));
    await tester.pumpAndSettle();

    expect(find.text('Roberto García'), findsOneWidget);
    expect(carrito().vacio, isTrue);
  });
}
