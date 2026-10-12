import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:footwearpoint/models/estado_pedido.dart';
import 'package:footwearpoint/models/pedido_resumen.dart';
import 'package:footwearpoint/providers/auth_provider.dart';
import 'package:footwearpoint/screens/pedido_detalle_screen.dart';
import 'package:footwearpoint/services/api_service.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:provider/provider.dart';

/// TG-125 (E13-02): el cliente sabe hasta cuándo recoger su pedido.

http.Response _json(Object cuerpo, int codigo) => http.Response(
  jsonEncode(cuerpo),
  codigo,
  headers: {'content-type': 'application/json; charset=utf-8'},
);

Map<String, dynamic> _pedido(String estado, {String? limite}) => {
  'id': 7,
  'folio': 'PED-007',
  'tipo': 'cliente_directo',
  'estado': estado,
  'total': 800.0,
  'pagado': 100.0,
  'pagado_con_vales': 0.0,
  'saldo': 700.0,
  'anticipo_requerido': 100.0,
  'anticipo_pagado': 100.0,
  'anticipo_pendiente': 0.0,
  'fecha_limite_recoleccion': limite,
  'lineas': <Object>[],
  'pagos': <Object>[],
};

void main() {
  setUp(() => FlutterSecureStorage.setMockInitialValues(<String, String>{'token_sanctum': '1|token'}));

  group('modelo', () {
    test('lee la fecha límite y arma el aviso solo si está listo', () {
      // Mediodía: el día no cambia en ninguna zona horaria de México.
      final listo = PedidoResumen.desdeJson(_pedido('listo_entrega', limite: '2026-10-15T12:00:00-06:00'));
      expect(listo.fechaLimiteRecoleccion, isNotNull);
      expect(listo.avisoRecoleccion, 'Recoge antes del 15/10/2026');

      final entregado = PedidoResumen.desdeJson(_pedido('entregado', limite: '2026-10-15T12:00:00-06:00'));
      expect(entregado.avisoRecoleccion, isNull);

      final sinCampo = PedidoResumen.desdeJson(_pedido('listo_entrega')..remove('fecha_limite_recoleccion'));
      expect(sinCampo.fechaLimiteRecoleccion, isNull);
      expect(sinCampo.avisoRecoleccion, isNull);
    });

    test('el estado vencido se llama «Recolección vencida»', () {
      final estado = EstadoPedido.de('vencido_recoleccion');
      expect(estado.etiqueta, 'Recolección vencida');
      expect(estado.tono, TonoEstado.peligro);
      expect(estado.descripcion, isNotNull);
    });
  });

  Future<void> abrir(WidgetTester tester, Map<String, dynamic> pedido) async {
    final servidor = MockClient((peticion) async {
      if (peticion.url.path.endsWith('/pedidos/7')) return _json({'data': pedido}, 200);
      return _json({'data': <Object>[]}, 200);
    });

    await tester.pumpWidget(
      ChangeNotifierProvider(
        create: (_) => AuthProvider(api: ApiService(cliente: servidor)),
        child: const MaterialApp(home: PedidoDetalleScreen(pedidoId: 7)),
      ),
    );
    await tester.pumpAndSettle();
  }

  testWidgets('pedido listo: el detalle dice hasta cuándo recogerlo', (tester) async {
    await abrir(tester, _pedido('listo_entrega', limite: '2026-10-15T12:00:00-06:00'));

    expect(find.text('Recoge antes del 15/10/2026'), findsOneWidget);
  });

  testWidgets('pedido vencido: muestra «Recolección vencida» y no la fecha', (tester) async {
    await abrir(tester, _pedido('vencido_recoleccion', limite: '2026-10-09T12:00:00-06:00'));

    expect(find.text('Recolección vencida'), findsOneWidget);
    expect(find.textContaining('Comunícate con tu distribuidora'), findsOneWidget);
    expect(find.textContaining('Recoge antes del'), findsNothing);
  });
}
