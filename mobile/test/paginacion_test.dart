import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:footwearpoint/models/pagina.dart';
import 'package:footwearpoint/providers/auth_provider.dart';
import 'package:footwearpoint/screens/mis_pedidos_screen.dart';
import 'package:footwearpoint/services/api_service.dart';
import 'package:footwearpoint/services/pedido_service.dart';
import 'package:footwearpoint/services/vale_service.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:provider/provider.dart';

/// Auditoría 21-oct-2026: la API pagina pedidos y vales (data + meta + links).
http.Response _json(Object cuerpo) => http.Response(
  jsonEncode(cuerpo),
  200,
  headers: {'content-type': 'application/json; charset=utf-8'},
);

Map<String, dynamic> _pedido(int id, String estado) => {
  'id': id,
  'folio': 'PED-$id',
  'tipo': 'cliente_directo',
  'estado': estado,
  'total': 100.0,
  'pagado': 0.0,
  'pagado_con_vales': 0.0,
  'saldo': 100.0,
  'anticipo_requerido': 0.0,
  'anticipo_pagado': 0.0,
  'anticipo_pendiente': 0.0,
  'fecha_colocacion': '2026-09-18T10:30:00-06:00',
};

Map<String, dynamic> _meta(int actual, int ultima) => {
  'current_page': actual,
  'last_page': ultima,
  'per_page': 25,
  'total': 30,
};

void main() {
  setUp(() => FlutterSecureStorage.setMockInitialValues(<String, String>{'token_sanctum': '1|token'}));

  test('sin meta (servidor viejo) es una sola página', () {
    final pagina = Pagina.desdeJson({'data': [{'x': 1}]}, (j) => j['x'] as int);

    expect(pagina.elementos, [1]);
    expect(pagina.hayMas, isFalse);
  });

  test('pedidos: pide page y per_page, quita borradores y dice si hay más', () async {
    final pedidas = <Uri>[];
    final api = ApiService(
      cliente: MockClient((peticion) async {
        pedidas.add(peticion.url);
        final pagina = int.parse(peticion.url.queryParameters['page'] ?? '1');

        return _json({
          'data': pagina == 1 ? [_pedido(3, 'colocado'), _pedido(2, 'borrador')] : [_pedido(1, 'entregado')],
          'meta': _meta(pagina, 2),
          'links': {'next': null, 'prev': null},
        });
      }),
    );

    final primera = await PedidoService(api).listarPagina();
    expect(primera.elementos.map((p) => p.id), [3]);
    expect(primera.hayMas, isTrue);
    expect(pedidas.last.queryParameters, {'page': '1', 'per_page': '25'});

    final segunda = await PedidoService(api).listarPagina(pagina: 2);
    expect(segunda.elementos.map((p) => p.id), [1]);
    expect(segunda.hayMas, isFalse);
  });

  test('vales: junta todas las páginas', () async {
    final api = ApiService(
      cliente: MockClient((peticion) async {
        final pagina = int.parse(peticion.url.queryParameters['page'] ?? '1');
        final vale = {
          'id': pagina,
          'folio': 'VAL-$pagina',
          'monto_original': 100.0,
          'saldo_disponible': 100.0,
          'estado': 'activo',
          'fecha_emision': '2026-09-01',
          'fecha_vencimiento': '2026-12-01',
        };

        return _json({'data': [vale], 'meta': _meta(pagina, 3)});
      }),
    );

    final vales = await ValeService(api: api).listarValesVigentes();
    expect(vales.length, 3);
  });

  testWidgets('Mis pedidos pide la siguiente página al llegar al final', (tester) async {
    final paginas = <String>[];
    final api = ApiService(
      cliente: MockClient((peticion) async {
        if (peticion.url.path.endsWith('/pedidos')) {
          final pagina = int.parse(peticion.url.queryParameters['page'] ?? '1');
          paginas.add('$pagina');
          final ids = pagina == 1 ? List.generate(25, (i) => 100 - i) : [1];

          return _json({'data': [for (final id in ids) _pedido(id, 'colocado')], 'meta': _meta(pagina, 2)});
        }
        return _json({'message': 'No encontrado.'});
      }),
    );

    await tester.pumpWidget(
      ChangeNotifierProvider(
        create: (_) => AuthProvider(api: api),
        child: const MaterialApp(home: MisPedidosScreen()),
      ),
    );
    await tester.pumpAndSettle();
    expect(paginas, ['1']);

    await tester.scrollUntilVisible(find.text('PED-1'), 500, scrollable: find.byType(Scrollable).last);
    await tester.pumpAndSettle();

    expect(paginas, ['1', '2']);
    expect(find.text('PED-1'), findsOneWidget);
    expect(find.text('Ver más pedidos'), findsNothing);
  });
}
