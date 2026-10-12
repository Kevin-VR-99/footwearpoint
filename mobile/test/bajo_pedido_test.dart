import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:footwearpoint/models/pedido_resumen.dart';
import 'package:footwearpoint/models/producto_catalogo.dart';
import 'package:footwearpoint/providers/auth_provider.dart';
import 'package:footwearpoint/providers/carrito_revendedor_provider.dart';
import 'package:footwearpoint/screens/crear_pedido_screen.dart';
import 'package:footwearpoint/screens/pedido_detalle_screen.dart';
import 'package:footwearpoint/screens/pedido_revendedor_screen.dart';
import 'package:footwearpoint/services/api_service.dart';
import 'package:footwearpoint/widgets/bajo_pedido.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:provider/provider.dart';

/// TG-266 (E9-03): "bajo pedido" se ve claro en toda la app.

http.Response _json(Object cuerpo, int codigo) => http.Response(
  jsonEncode(cuerpo),
  codigo,
  headers: {'content-type': 'application/json; charset=utf-8'},
);

Map<String, dynamic> _variante(int id, String talla, String disponibilidad) => {
  'variante_id': id,
  'sku': 'S-$id',
  'talla': talla,
  'color': 'Negro',
  'nombre_color_comercial': null,
  'disponibilidad': disponibilidad,
};

ProductoCatalogo _producto(List<Map<String, dynamic>> variantes, {bool revendedor = false}) =>
    ProductoCatalogo.desdeJson({
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
      if (revendedor) 'precio_mayorista': 500,
      'imagenes': <Object>[],
      'variantes': variantes,
    });

Map<String, dynamic> _linea(int id, String talla, {bool? bajoPedido}) => {
  'id': id,
  'producto_nombre': 'Botín casual',
  'modelo': 'MOD-01',
  'talla': talla,
  'color': 'Negro',
  'cantidad': 1,
  'precio_unitario': 800.0,
  'subtotal': 800.0,
  'bajo_pedido': ?bajoPedido,
};

Map<String, dynamic> _pedido(List<Map<String, dynamic>> lineas) => {
  'id': 7,
  'folio': 'PED-007',
  'tipo': 'cliente_directo',
  'estado': 'colocado',
  'total': 1600.0,
  'pagado': 200.0,
  'pagado_con_vales': 0.0,
  'saldo': 1400.0,
  'anticipo_requerido': 200.0,
  'anticipo_pagado': 200.0,
  'anticipo_pendiente': 0.0,
  'lineas': lineas,
  'pagos': [],
};

/// Un servidor que a todo responde vacío, salvo el pedido 7.
MockClient _servidor({List<Map<String, dynamic>> lineas = const []}) => MockClient((peticion) async {
  if (peticion.url.path.endsWith('/pedidos/7')) return _json({'data': _pedido(lineas)}, 200);
  return _json({'data': <Object>[]}, 200);
});

void main() {
  setUp(() => FlutterSecureStorage.setMockInitialValues(<String, String>{'token_sanctum': '1|token'}));

  group('modelo', () {
    test('el producto sabe si alguna talla va bajo pedido', () {
      expect(_producto([_variante(1, '24', 'disponible'), _variante(2, '25', 'bajo_pedido')]).hayBajoPedido, isTrue);
      expect(_producto([_variante(1, '24', 'disponible'), _variante(2, '25', 'no_disponible')]).hayBajoPedido, isFalse);
    });

    test('la línea del pedido lee bajo_pedido y sin el campo queda en false', () {
      final pedido = PedidoResumen.desdeJson(_pedido([_linea(1, '24', bajoPedido: true), _linea(2, '25')]));

      expect(pedido.lineas[0].bajoPedido, isTrue);
      expect(pedido.lineas[1].bajoPedido, isFalse);
    });
  });

  testWidgets('cliente directo: al pedir una talla bajo pedido se avisa antes de enviar', (tester) async {
    final producto = _producto([_variante(9, '25', 'bajo_pedido')]);
    await tester.pumpWidget(
      ChangeNotifierProvider(
        create: (_) => AuthProvider(api: ApiService(cliente: _servidor())),
        child: MaterialApp(home: CrearPedidoScreen(producto: producto, variante: producto.variantes.first)),
      ),
    );
    await tester.pumpAndSettle();

    expect(find.text('Bajo pedido'), findsOneWidget);
    expect(find.text(textoBajoPedido), findsOneWidget);
  });

  testWidgets('cliente directo: una talla disponible no muestra el aviso', (tester) async {
    final producto = _producto([_variante(9, '24', 'disponible')]);
    await tester.pumpWidget(
      ChangeNotifierProvider(
        create: (_) => AuthProvider(api: ApiService(cliente: _servidor())),
        child: MaterialApp(home: CrearPedidoScreen(producto: producto, variante: producto.variantes.first)),
      ),
    );
    await tester.pumpAndSettle();

    expect(find.byType(InsigniaBajoPedido), findsNothing);
    expect(find.byType(AvisoBajoPedido), findsNothing);
  });

  testWidgets('carrito mayorista: marca la pieza bajo pedido y lo explica una vez', (tester) async {
    final producto = _producto([
      _variante(9, '24', 'disponible'),
      _variante(10, '25', 'bajo_pedido'),
    ], revendedor: true);
    final carrito = CarritoRevendedorProvider()
      ..usarSesion(7)
      ..agregar(producto, producto.variantes[0])
      ..agregar(producto, producto.variantes[1]);

    await tester.pumpWidget(
      MultiProvider(
        providers: [
          ChangeNotifierProvider(create: (_) => AuthProvider(api: ApiService(cliente: _servidor()))),
          ChangeNotifierProvider.value(value: carrito),
        ],
        child: const MaterialApp(home: PedidoRevendedorScreen()),
      ),
    );
    await tester.pumpAndSettle();

    expect(find.byType(InsigniaBajoPedido), findsOneWidget);
    expect(find.text(textoTallasBajoPedido), findsOneWidget);
  });

  testWidgets('detalle del pedido: marca las líneas bajo pedido', (tester) async {
    await tester.pumpWidget(
      ChangeNotifierProvider(
        create: (_) => AuthProvider(api: ApiService(cliente: _servidor(lineas: [
          _linea(1, '24', bajoPedido: false),
          _linea(2, '25', bajoPedido: true),
        ]))),
        child: const MaterialApp(home: PedidoDetalleScreen(pedidoId: 7)),
      ),
    );
    await tester.pumpAndSettle();

    expect(find.byType(InsigniaBajoPedido), findsOneWidget);
    expect(find.text(textoTallasBajoPedido), findsOneWidget);
  });

  testWidgets('detalle del pedido: sin líneas bajo pedido no hay aviso', (tester) async {
    await tester.pumpWidget(
      ChangeNotifierProvider(
        create: (_) => AuthProvider(api: ApiService(cliente: _servidor(lineas: [_linea(1, '24')]))),
        child: const MaterialApp(home: PedidoDetalleScreen(pedidoId: 7)),
      ),
    );
    await tester.pumpAndSettle();

    expect(find.byType(InsigniaBajoPedido), findsNothing);
    expect(find.byType(AvisoBajoPedido), findsNothing);
  });
}
