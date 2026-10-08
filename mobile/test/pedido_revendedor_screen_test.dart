import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:footwearpoint/models/producto_catalogo.dart';
import 'package:footwearpoint/models/vale.dart';
import 'package:footwearpoint/providers/auth_provider.dart';
import 'package:footwearpoint/providers/carrito_revendedor_provider.dart';
import 'package:footwearpoint/screens/pedido_revendedor_screen.dart';
import 'package:footwearpoint/services/api_service.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:provider/provider.dart';

/// Un producto con precio mayorista de $500 y dos tallas (variantes 9 y 10).
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

/// El pedido de revendedor como lo manda PedidoResource: 3 pares × $500.
/// Con [pagadoConVale], como queda después de aplicar el vale (TG-167).
Map<String, dynamic> _pedido({double pagadoConVale = 0}) => {
  'id': 60,
  'folio': 'PED-20260918-0010',
  'tipo': 'revendedor',
  'estado': 'colocado',
  'total': 1500.0,
  'pagado': pagadoConVale,
  'pagado_con_vales': pagadoConVale,
  'saldo': 1500.0 - pagadoConVale,
  'anticipo_requerido': 0.0,
  'anticipo_pagado': 0.0,
  'anticipo_pendiente': 0.0,
};

Map<String, dynamic> _vale({
  int id = 5,
  double saldo = 300,
  String estado = 'activo',
  String vence = '2099-11-30T10:00:00-06:00',
}) => {
  'id': id,
  'folio': 'VAL-000$id',
  'monto_original': 500.0,
  'saldo_actual': saldo,
  'fecha_emision': '2026-09-01T10:00:00-06:00',
  'fecha_vencimiento': vence,
  'estado': estado,
  'motivo': 'Pedido no surtido',
};

/// Un servidor falso que anota todo lo que se le pide.
class _Servidor {
  _Servidor({this.vales = const [], this.aplicarFalla = false, this.lineaFalla = false});

  final List<Map<String, dynamic>> vales;
  final bool aplicarFalla;
  final bool lineaFalla;
  final peticiones = <http.Request>[];
  double montoAplicado = 0;

  MockClient get cliente => MockClient((peticion) async {
    peticiones.add(peticion);
    final ruta = peticion.url.path;

    if (ruta.endsWith('/vales') && peticion.method == 'GET') return _json({'data': vales}, 200);
    if (ruta.endsWith('/pedidos') && peticion.method == 'POST') {
      return _json({'data': {'id': 60, 'folio': 'PED-20260918-0010', 'estado': 'borrador'}}, 201);
    }
    if (ruta.endsWith('/pedidos/60/lineas')) {
      if (lineaFalla) {
        return _json({
          'message': 'La variante no está disponible.',
          'errors': {'variante_id': ['La variante no está disponible en catálogo.']},
        }, 422);
      }
      return _json({'data': _pedido()}, 201);
    }
    if (ruta.endsWith('/pedidos/60/enviar')) return _json({'data': _pedido()}, 200);
    if (ruta.endsWith('/aplicar')) {
      if (aplicarFalla) {
        return _json({'message': 'El vale está vencido.', 'errors': {'vale': ['El vale está vencido.']}}, 422);
      }
      montoAplicado = (jsonDecode(peticion.body) as Map<String, dynamic>)['monto'] as double;
      return _json({'data': {}, 'message': 'Vale aplicado correctamente.'}, 200);
    }
    if (ruta.endsWith('/pedidos/60') && peticion.method == 'GET') {
      return _json({'data': _pedido(pagadoConVale: montoAplicado)}, 200);
    }

    return _json({'message': 'Not Found'}, 404);
  });

  List<String> get rutas => [for (final p in peticiones) '${p.method} ${p.url.path.replaceFirst('/api', '')}'];

  List<Map<String, dynamic>> cuerpos(String sufijo) => [
    for (final p in peticiones)
      if (p.url.path.endsWith(sufijo)) jsonDecode(p.body) as Map<String, dynamic>,
  ];
}

void main() {
  setUp(() => FlutterSecureStorage.setMockInitialValues(<String, String>{'token_sanctum': '1|token'}));

  /// Abre el carrito con 2 pares de la talla 24 y 1 de la 25 ($1,500).
  Future<CarritoRevendedorProvider> abrir(WidgetTester tester, _Servidor servidor) async {
    final producto = _producto();
    final carrito = CarritoRevendedorProvider()
      ..usarSesion(7)
      ..agregar(producto, producto.variantes[0])
      ..agregar(producto, producto.variantes[0])
      ..agregar(producto, producto.variantes[1]);

    await tester.pumpWidget(
      MultiProvider(
        providers: [
          ChangeNotifierProvider(create: (_) => AuthProvider(api: ApiService(cliente: servidor.cliente))),
          ChangeNotifierProvider.value(value: carrito),
        ],
        child: const MaterialApp(home: PedidoRevendedorScreen()),
      ),
    );
    await tester.pumpAndSettle();

    return carrito;
  }

  Future<void> elegirVale(WidgetTester tester, String folio) async {
    await tester.tap(find.byType(DropdownButtonFormField<Vale?>));
    await tester.pumpAndSettle();
    await tester.tap(find.textContaining(folio).last);
    await tester.pumpAndSettle();
  }

  Future<void> enviar(WidgetTester tester) async {
    await tester.tap(find.text('Enviar Pedido Completo'));
    await tester.pumpAndSettle();
  }

  testWidgets('el vale se aplica al pedido ya enviado y se ven los números del servidor', (tester) async {
    final servidor = _Servidor(vales: [_vale(saldo: 300)]);
    final carrito = await abrir(tester, servidor);

    await elegirVale(tester, 'VAL-0005');
    await enviar(tester);

    expect(servidor.rutas, containsAllInOrder([
      'POST /pedidos',
      'POST /pedidos/60/lineas',
      'POST /pedidos/60/lineas',
      'POST /pedidos/60/enviar',
      'POST /vales/5/aplicar',
      'GET /pedidos/60',
    ]));
    // El vale tiene menos de lo que se debe: se toma todo el vale.
    expect(servidor.cuerpos('/aplicar').single, {'monto': 300.0, 'pedido_id': 60});

    expect(find.text('¡Pedido enviado!'), findsOneWidget);
    expect(find.text('Folio PED-20260918-0010'), findsOneWidget);
    expect(find.text('Pagado con vale'), findsOneWidget);
    expect(find.text(r'-$300.00'), findsOneWidget);
    expect(find.text('Pendiente por pagar'), findsOneWidget);
    expect(find.text(r'$1,200.00'), findsOneWidget);
    // "pagado" incluye el vale: no se muestra otra vez como otro pago.
    expect(find.text('Otros pagos'), findsNothing);
    expect(carrito.vacio, isTrue);
  });

  testWidgets('un vale mayor a lo que se debe solo aplica lo que falta', (tester) async {
    final servidor = _Servidor(vales: [_vale(saldo: 2000)]);
    await abrir(tester, servidor);

    await elegirVale(tester, 'VAL-0005');
    await enviar(tester);

    expect(servidor.cuerpos('/aplicar').single, {'monto': 1500.0, 'pedido_id': 60});
    expect(find.text(r'$0.00'), findsOneWidget);
  });

  testWidgets('sin vale se envía igual, sin aplicar nada', (tester) async {
    final servidor = _Servidor(vales: [_vale()]);
    final carrito = await abrir(tester, servidor);

    await enviar(tester);

    expect(servidor.rutas.where((r) => r.contains('aplicar')), isEmpty);
    expect(servidor.cuerpos('/pedidos/60/lineas'), [
      {'producto_campana_id': 12, 'variante_id': 9, 'cantidad': 2},
      {'producto_campana_id': 12, 'variante_id': 10, 'cantidad': 1},
    ]);
    expect(find.text('Pagado con vale'), findsNothing);
    expect(find.text(r'$1,500.00'), findsNWidgets(2));
    expect(carrito.vacio, isTrue);
  });

  testWidgets('solo se ofrecen vales activos, con saldo y sin vencer', (tester) async {
    await abrir(tester, _Servidor(vales: [
      _vale(id: 5),
      _vale(id: 6, saldo: 0),
      _vale(id: 7, estado: 'agotado'),
      _vale(id: 8, vence: '2020-01-01T10:00:00-06:00'),
    ]));

    await tester.tap(find.byType(DropdownButtonFormField<Vale?>));
    await tester.pumpAndSettle();

    expect(find.textContaining('VAL-0005'), findsWidgets);
    expect(find.textContaining('VAL-0006'), findsNothing);
    expect(find.textContaining('VAL-0007'), findsNothing);
    expect(find.textContaining('VAL-0008'), findsNothing);
  });

  testWidgets('si el vale falla, el pedido igual queda enviado y se avisa', (tester) async {
    final servidor = _Servidor(vales: [_vale()], aplicarFalla: true);
    final carrito = await abrir(tester, servidor);

    await elegirVale(tester, 'VAL-0005');
    await enviar(tester);

    expect(find.text('¡Pedido enviado!'), findsOneWidget);
    expect(find.textContaining('no se pudo aplicar el vale: El vale está vencido.'), findsOneWidget);
    expect(find.text(r'$1,500.00'), findsNWidgets(2));
    // Ya se envió: no se queda en el carrito para mandarlo otra vez.
    expect(carrito.vacio, isTrue);
  });

  testWidgets('si el servidor rechaza el pedido, el carrito se conserva', (tester) async {
    final servidor = _Servidor(vales: [_vale()], lineaFalla: true);
    final carrito = await abrir(tester, servidor);

    await elegirVale(tester, 'VAL-0005');
    await enviar(tester);

    expect(find.text('¡Pedido enviado!'), findsNothing);
    expect(servidor.rutas.where((r) => r.contains('aplicar')), isEmpty);
    expect(carrito.totalPiezas, 3);
    expect(find.text('Enviar Pedido Completo'), findsOneWidget);
  });
}
