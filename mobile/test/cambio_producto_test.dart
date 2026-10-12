import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:footwearpoint/models/pedido_resumen.dart';
import 'package:footwearpoint/providers/auth_provider.dart';
import 'package:footwearpoint/screens/pedido_detalle_screen.dart';
import 'package:footwearpoint/services/api_service.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:provider/provider.dart';

/// TG-254 (E12-01): pedir el cambio de un producto entregado desde la app.

http.Response _json(Object cuerpo, int codigo) => http.Response(
  jsonEncode(cuerpo),
  codigo,
  headers: {'content-type': 'application/json; charset=utf-8'},
);

Map<String, dynamic> _linea(int id, String nombre, Map<String, dynamic>? cambio) => {
  'id': id,
  'producto_nombre': nombre,
  'modelo': 'MOD-$id',
  'talla': '25',
  'color': 'Negro',
  'cantidad': 1,
  'precio_unitario': 900.0,
  'subtotal': 900.0,
  'cambio': cambio,
};

Map<String, dynamic> _pedido(List<Map<String, dynamic>> lineas) => {
  'id': 7,
  'folio': 'PED-007',
  'tipo': 'cliente_directo',
  'estado': 'entregado',
  'total': 1800.0,
  'pagado': 1800.0,
  'pagado_con_vales': 0.0,
  'saldo': 0.0,
  'anticipo_requerido': 0.0,
  'anticipo_pagado': 0.0,
  'anticipo_pendiente': 0.0,
  'fecha_colocacion': '2026-09-18T10:30:00-06:00',
  'fecha_entrega': '2026-10-01T10:00:00-06:00',
  'lineas': lineas,
  'pagos': [],
};

void main() {
  setUp(() => FlutterSecureStorage.setMockInitialValues(<String, String>{'token_sanctum': '1|token'}));

  group('modelo', () {
    test('lee id, cambio y fecha de entrega', () {
      final pedido = PedidoResumen.desdeJson(_pedido([
        _linea(11, 'Bota', {'puede_solicitar': true, 'fecha_limite': '2026-10-13T23:59:59-06:00', 'solicitud': null}),
        _linea(12, 'Tenis', {'puede_solicitar': false, 'fecha_limite': '2026-10-13T23:59:59-06:00', 'solicitud': {'id': 4, 'estado': 'solicitada'}}),
      ]));

      expect(pedido.fechaEntrega, isNotNull);
      expect(pedido.lineas[0].id, 11);
      expect(pedido.lineas[0].cambio!.puedeSolicitar, isTrue);
      expect(pedido.lineas[0].cambio!.fechaLimiteTexto, '13/10/2026');
      expect(pedido.lineas[1].cambio!.tieneSolicitud, isTrue);
      expect(pedido.lineas[1].cambio!.estadoParaMostrar, 'Cambio solicitado');
    });

    test('sin los campos nuevos (servidor anterior) no truena', () {
      final pedido = PedidoResumen.desdeJson({
        ..._pedido([
          {'producto_nombre': 'Bota', 'modelo': 'M', 'talla': '25', 'color': 'Negro', 'cantidad': 1, 'precio_unitario': 1.0, 'subtotal': 1.0},
        ]),
      }..remove('fecha_entrega'));

      expect(pedido.fechaEntrega, isNull);
      expect(pedido.lineas.single.id, isNull);
      expect(pedido.lineas.single.cambio, isNull);
    });
  });

  group('pantalla', () {
    Future<List<String>> abrir(WidgetTester tester, {required List<Map<String, dynamic>> lineas, http.Response? respuestaCambio}) async {
      final peticiones = <String>[];

      final servidor = MockClient((peticion) async {
        final ruta = peticion.url.path.replaceFirst('/api', '');
        peticiones.add('${peticion.method} $ruta ${peticion.body}');

        if (ruta == '/pedidos/7') return _json({'data': _pedido(lineas)}, 200);
        if (ruta == '/solicitudes-cambio' && peticion.method == 'POST') {
          return respuestaCambio ?? _json({'data': {'id': 1}, 'message': 'Solicitud de cambio enviada.'}, 201);
        }
        return _json({'message': 'No encontrado.'}, 404);
      });

      await tester.pumpWidget(
        ChangeNotifierProvider(
          create: (_) => AuthProvider(api: ApiService(cliente: servidor)),
          child: const MaterialApp(home: PedidoDetalleScreen(pedidoId: 7)),
        ),
      );
      await tester.pumpAndSettle();

      return peticiones;
    }

    testWidgets('dentro del plazo muestra el botón y envía el motivo', (tester) async {
      final peticiones = await abrir(tester, lineas: [
        _linea(11, 'Bota', {'puede_solicitar': true, 'fecha_limite': '2026-10-13T23:59:59-06:00', 'solicitud': null}),
      ]);

      expect(find.text('Puedes pedir cambio hasta el 13/10/2026'), findsOneWidget);
      expect(find.text('El cambio se entrega como vale; no hay devoluciones en efectivo.'), findsOneWidget);

      await tester.tap(find.text('Solicitar cambio'));
      await tester.pumpAndSettle();

      // Sin motivo no se envía.
      await tester.tap(find.text('Enviar'));
      await tester.pumpAndSettle();
      expect(find.text('Escribe el motivo del cambio.'), findsOneWidget);

      await tester.enterText(find.byKey(const Key('motivo_cambio')), 'No me quedó');
      await tester.tap(find.text('Enviar'));
      await tester.pumpAndSettle();

      final post = peticiones.firstWhere((p) => p.startsWith('POST /solicitudes-cambio'));
      expect(jsonDecode(post.substring(post.indexOf('{'))), {'pedido_detalle_id': 11, 'motivo': 'No me quedó'});
      expect(find.text('Solicitud de cambio enviada.'), findsOneWidget);
      // Vuelve a pedir el pedido para mostrar el estado nuevo.
      expect(peticiones.where((p) => p.startsWith('GET /pedidos/7')).length, 2);
    });

    testWidgets('muestra el mensaje del servidor si no se puede', (tester) async {
      await abrir(
        tester,
        lineas: [_linea(11, 'Bota', {'puede_solicitar': true, 'fecha_limite': '2026-10-13T23:59:59-06:00', 'solicitud': null})],
        respuestaCambio: _json({'message': 'Ese producto ya tiene una solicitud de cambio en trámite.'}, 409),
      );

      await tester.tap(find.text('Solicitar cambio'));
      await tester.pumpAndSettle();
      await tester.enterText(find.byKey(const Key('motivo_cambio')), 'Talla');
      await tester.tap(find.text('Enviar'));
      await tester.pumpAndSettle();

      expect(find.text('Ese producto ya tiene una solicitud de cambio en trámite.'), findsOneWidget);
    });

    testWidgets('con solicitud muestra su estado y fuera de plazo no muestra nada', (tester) async {
      await abrir(tester, lineas: [
        _linea(11, 'Bota', {'puede_solicitar': false, 'fecha_limite': '2026-10-13T23:59:59-06:00', 'solicitud': {'id': 3, 'estado': 'solicitada'}}),
        _linea(12, 'Sandalia', {'puede_solicitar': false, 'fecha_limite': '2026-09-01T23:59:59-06:00', 'solicitud': null}),
      ]);

      expect(find.text('Cambio solicitado'), findsOneWidget);
      expect(find.text('Solicitar cambio'), findsNothing);
      expect(find.textContaining('Puedes pedir cambio'), findsNothing);
    });
  });
}
