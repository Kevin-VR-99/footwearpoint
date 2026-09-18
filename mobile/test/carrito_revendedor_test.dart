import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:footwearpoint/main.dart';
import 'package:footwearpoint/models/item_pedido_revendedor.dart';
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
    setUp(() => FlutterSecureStorage.setMockInitialValues(<String, String>{}));

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

  group('guardado en el teléfono (TG-165)', () {
    setUp(() => FlutterSecureStorage.setMockInitialValues(<String, String>{}));

    /// Como abrir la app de nuevo: un carrito nuevo que lee lo guardado.
    Future<CarritoRevendedorProvider> abrirApp(int usuarioId) async {
      final carrito = CarritoRevendedorProvider()..usarSesion(usuarioId);
      await carrito.listo;
      return carrito;
    }

    test('al volver a abrir la app, el mismo usuario recupera su carrito', () async {
      final producto = _producto();
      final antes = await abrirApp(7);
      antes
        ..agregar(producto, producto.variantes[0])
        ..agregar(producto, producto.variantes[0])
        ..agregar(producto, producto.variantes[1]);
      await antes.listo;

      final despues = await abrirApp(7);

      expect(despues.items, hasLength(2));
      expect(despues.items[0].variante.varianteId, 9);
      expect(despues.items[0].cantidad, 2);
      expect(despues.items[1].variante.talla, '25');
      expect(despues.items[0].producto.nombre, 'Botín casual');
      // Con precio mayorista: el total estimado sale igual que antes.
      expect(despues.total, 1500);
    });

    test('cada usuario tiene el suyo, y lo recupera después de cerrar sesión', () async {
      final producto = _producto();
      final carrito = await abrirApp(7);
      carrito.agregar(producto, producto.variantes[0]);
      await carrito.listo;

      // Cierra sesión y entra otro revendedor: no ve el de María.
      carrito.usarSesion(null);
      carrito.usarSesion(9);
      await carrito.listo;
      expect(carrito.vacio, isTrue);

      carrito.agregar(producto, producto.variantes[1]);
      await carrito.listo;

      // María vuelve a entrar: tiene el suyo, no el de Roberto.
      carrito.usarSesion(null);
      carrito.usarSesion(7);
      await carrito.listo;
      expect(carrito.items.single.variante.varianteId, 9);

      // Y el de Roberto sigue guardado.
      final roberto = await abrirApp(9);
      expect(roberto.items.single.variante.varianteId, 10);
    });

    test('al enviar el pedido (vaciar) se borra lo guardado', () async {
      final producto = _producto();
      final carrito = await abrirApp(7);
      carrito.agregar(producto, producto.variantes[0]);
      await carrito.listo;

      carrito.vaciar();
      await carrito.listo;

      expect((await abrirApp(7)).vacio, isTrue);
      expect(await const FlutterSecureStorage().read(key: CarritoRevendedorProvider.llaveDe(7)), isNull);
    });

    test('quitar y cambiar cantidades también se guarda', () async {
      final producto = _producto();
      final carrito = await abrirApp(7);
      carrito
        ..agregar(producto, producto.variantes[0])
        ..agregar(producto, producto.variantes[1]);
      carrito.cambiarCantidad(carrito.items.first, 5);
      carrito.quitar(carrito.items.last);
      await carrito.listo;

      final despues = await abrirApp(7);
      expect(despues.items.single.variante.varianteId, 9);
      expect(despues.items.single.cantidad, 5);
    });

    test('si lo guardado está dañado, arranca vacío sin tronar', () async {
      FlutterSecureStorage.setMockInitialValues(<String, String>{
        CarritoRevendedorProvider.llaveDe(7): 'esto no es json',
      });

      final carrito = await abrirApp(7);
      expect(carrito.vacio, isTrue);

      // Y se puede seguir usando.
      final producto = _producto();
      carrito.agregar(producto, producto.variantes[0]);
      expect(carrito.totalPiezas, 1);
    });
  });

  testWidgets('con un carrito guardado, el inicio lo muestra y lo abre', (tester) async {
    // Lo que dejó guardado María la última vez que usó la app.
    final producto = _producto();
    FlutterSecureStorage.setMockInitialValues(<String, String>{
      'token_sanctum': '1|token-maria',
      CarritoRevendedorProvider.llaveDe(7): jsonEncode([
        ItemPedidoRevendedor(producto: producto, variante: producto.variantes[0], cantidad: 2).aJson(),
        ItemPedidoRevendedor(producto: producto, variante: producto.variantes[1]).aJson(),
      ]),
    });

    final servidor = MockClient((peticion) async {
      if (peticion.url.path.endsWith('/auth/me')) {
        return _json({'data': _sesion(7, 'María López', 'maria@ejemplo.com')}, 200);
      }
      return _json({'data': <Object>[]}, 200);
    });

    await tester.pumpWidget(FootwearPointApp(api: ApiService(cliente: servidor)));
    await tester.pumpAndSettle();

    expect(find.text('Mi pedido acumulado (3)'), findsOneWidget);

    await tester.ensureVisible(find.text('Mi pedido acumulado (3)'));
    await tester.tap(find.text('Mi pedido acumulado (3)'));
    await tester.pumpAndSettle();

    expect(find.text('Pedido Acumulado de Revendedor'), findsOneWidget);
    expect(find.text('Botín casual'), findsNWidgets(2));
    expect(find.text(r'$1,500.00'), findsOneWidget);
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
