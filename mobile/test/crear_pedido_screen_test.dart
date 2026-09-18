import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:footwearpoint/models/producto_catalogo.dart';
import 'package:footwearpoint/models/vale.dart';
import 'package:footwearpoint/providers/auth_provider.dart';
import 'package:footwearpoint/screens/crear_pedido_screen.dart';
import 'package:footwearpoint/services/api_service.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:provider/provider.dart';

/// Producto del catálogo como lo recibe un cliente directo (sin mayorista).
ProductoCatalogo _producto({bool revendedor = false}) => ProductoCatalogo.desdeJson({
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
  'variantes': [
    {'variante_id': 9, 'sku': 'S-9', 'talla': '24', 'color': 'Negro', 'nombre_color_comercial': null, 'disponibilidad': 'disponible'},
  ],
});

http.Response _json(Object cuerpo, int codigo) => http.Response(
  jsonEncode(cuerpo),
  codigo,
  headers: {'content-type': 'application/json; charset=utf-8'},
);

/// El pedido como lo manda PedidoResource.
Map<String, dynamic> _pedido({double pagado = 0}) => {
  'id': 55,
  'folio': 'PED-20260918-0007',
  'estado': 'colocado',
  'total': 1600.0,
  'pagado': pagado,
  'saldo': 1600.0 - pagado,
  'anticipo_requerido': 200.0,
  'anticipo_pagado': 0.0,
  'anticipo_pendiente': 200.0,
};

/// Un servidor falso que anota todo lo que se le pide.
class _Servidor {
  _Servidor({this.vales = const [], this.aplicarFalla = false, this.lineaFalla = false});

  final List<Map<String, dynamic>> vales;
  final bool aplicarFalla;
  final bool lineaFalla;
  final peticiones = <http.Request>[];

  MockClient get cliente => MockClient((peticion) async {
    peticiones.add(peticion);
    final ruta = peticion.url.path;

    if (ruta.endsWith('/vales') && peticion.method == 'GET') return _json({'data': vales}, 200);
    if (ruta.endsWith('/pedidos') && peticion.method == 'POST') {
      return _json({'data': {'id': 55, 'folio': 'PED-20260918-0007', 'estado': 'borrador'}}, 201);
    }
    if (ruta.endsWith('/pedidos/55/lineas')) {
      if (lineaFalla) {
        return _json({
          'message': 'La variante no está disponible.',
          'errors': {'variante_id': ['La variante no está disponible en catálogo (estado: no_disponible).']},
        }, 422);
      }
      return _json({'data': _pedido()}, 201);
    }
    if (ruta.endsWith('/pedidos/55/enviar')) return _json({'data': _pedido()}, 200);
    if (ruta.endsWith('/aplicar')) {
      if (aplicarFalla) {
        return _json({'message': 'El vale está vencido.', 'errors': {'vale': ['El vale está vencido.']}}, 422);
      }
      return _json({'data': {}, 'message': 'Vale aplicado correctamente.'}, 200);
    }
    if (ruta.endsWith('/pedidos/55') && peticion.method == 'GET') return _json({'data': _pedido()}, 200);

    return _json({'message': 'Not Found'}, 404);
  });

  List<String> get rutas => [for (final p in peticiones) '${p.method} ${p.url.path.replaceFirst('/api', '')}'];

  Map<String, dynamic> cuerpo(String sufijo) =>
      jsonDecode(peticiones.firstWhere((p) => p.url.path.endsWith(sufijo)).body) as Map<String, dynamic>;
}

Map<String, dynamic> _vale({double saldo = 300}) => {
  'id': 5,
  'folio': 'VAL-0005',
  'monto_original': 500.0,
  'saldo_actual': saldo,
  'fecha_emision': '2026-09-01T10:00:00-06:00',
  'fecha_vencimiento': '2099-11-30T10:00:00-06:00',
  'estado': 'activo',
  'motivo': 'Pedido no surtido',
};

void main() {
  setUp(() => FlutterSecureStorage.setMockInitialValues(<String, String>{'token_sanctum': '1|token'}));

  Future<void> abrir(WidgetTester tester, _Servidor servidor, {bool revendedor = false}) async {
    final producto = _producto(revendedor: revendedor);

    await tester.pumpWidget(
      ChangeNotifierProvider(
        create: (_) => AuthProvider(api: ApiService(cliente: servidor.cliente)),
        child: MaterialApp(
          home: CrearPedidoScreen(producto: producto, variante: producto.variantes.first),
        ),
      ),
    );
    await tester.pumpAndSettle();
  }

  Future<void> enviar(WidgetTester tester) async {
    await tester.ensureVisible(find.text('Enviar pedido'));
    await tester.tap(find.text('Enviar pedido'));
    await tester.pumpAndSettle();
  }

  testWidgets('crea el pedido de verdad: crear, línea sin precio y enviar', (tester) async {
    final servidor = _Servidor();
    await abrir(tester, servidor);

    // Dos pares.
    await tester.tap(find.byTooltip('Un par más'));
    await tester.pump();
    await enviar(tester);

    expect(servidor.rutas, containsAllInOrder([
      'POST /pedidos',
      'POST /pedidos/55/lineas',
      'POST /pedidos/55/enviar',
    ]));

    expect(servidor.cuerpo('/pedidos')['tipo'], 'cliente_directo');
    final linea = servidor.cuerpo('/pedidos/55/lineas');
    expect(linea, {'producto_campana_id': 12, 'variante_id': 9, 'cantidad': 2});
    // El precio lo decide el servidor (Kevin, #166).
    expect(linea.containsKey('precio_unitario'), isFalse);
    // Ya no se aplica ningún vale a un pedido inventado.
    expect(servidor.rutas.where((r) => r.contains('aplicar')), isEmpty);
  });

  testWidgets('después de enviar muestra el folio y el anticipo a pagar en mostrador', (tester) async {
    await abrir(tester, _Servidor());
    await enviar(tester);

    expect(find.text('¡Pedido enviado!'), findsOneWidget);
    expect(find.text('Folio PED-20260918-0007'), findsOneWidget);
    expect(find.text('Anticipo a pagar en mostrador'), findsOneWidget);
    expect(find.text(r'$200.00'), findsOneWidget);
    // Ya no hay formulario de método de pago ni referencia (opción A).
    expect(find.textContaining('Método de pago'), findsNothing);
  });

  testWidgets('el vale se aplica al pedido real, como máximo por lo que se debe', (tester) async {
    // Vale de $2,000 para un pedido de $1,600: solo deben tomarse $1,600.
    final servidor = _Servidor(vales: [_vale(saldo: 2000)]);
    await abrir(tester, servidor);

    await tester.tap(find.byType(DropdownButtonFormField<Vale?>));
    await tester.pumpAndSettle();
    await tester.tap(find.textContaining('VAL-0005').last);
    await tester.pumpAndSettle();

    await enviar(tester);

    expect(servidor.rutas, containsAllInOrder([
      'POST /pedidos/55/enviar',
      'POST /vales/5/aplicar',
      'GET /pedidos/55',
    ]));
    expect(servidor.cuerpo('/aplicar'), {'monto': 1600.0, 'pedido_id': 55});
    expect(find.text('Vale aplicado'), findsOneWidget);
  });

  testWidgets('si el vale falla, el pedido igual queda enviado y se avisa', (tester) async {
    final servidor = _Servidor(vales: [_vale()], aplicarFalla: true);
    await abrir(tester, servidor);

    await tester.tap(find.byType(DropdownButtonFormField<Vale?>));
    await tester.pumpAndSettle();
    await tester.tap(find.textContaining('VAL-0005').last);
    await tester.pumpAndSettle();

    await enviar(tester);

    expect(find.text('¡Pedido enviado!'), findsOneWidget);
    expect(find.textContaining('no se pudo aplicar el vale: El vale está vencido.'), findsOneWidget);
  });

  testWidgets('si el servidor rechaza el pedido, se queda en el formulario con el motivo', (tester) async {
    await abrir(tester, _Servidor(lineaFalla: true));
    await enviar(tester);

    expect(find.text('¡Pedido enviado!'), findsNothing);
    expect(find.textContaining('no está disponible en catálogo'), findsOneWidget);
    expect(find.text('Enviar pedido'), findsOneWidget);
  });

  testWidgets('un revendedor manda su pedido directo como revendedor y ve lo pendiente', (tester) async {
    final servidor = _Servidor();
    await abrir(tester, servidor, revendedor: true);

    expect(find.text('Precio mayorista'), findsOneWidget);
    await enviar(tester);

    expect(servidor.cuerpo('/pedidos')['tipo'], 'revendedor');
    expect(find.text('Pendiente por pagar'), findsOneWidget);
    expect(find.text('Anticipo a pagar en mostrador'), findsNothing);
  });
}
