import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:footwearpoint/providers/auth_provider.dart';
import 'package:footwearpoint/screens/mis_pedidos_screen.dart';
import 'package:footwearpoint/services/api_service.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:provider/provider.dart';

http.Response _json(Object cuerpo, int codigo) => http.Response(
  jsonEncode(cuerpo),
  codigo,
  headers: {'content-type': 'application/json; charset=utf-8'},
);

/// Un pedido como lo manda PedidoResource (la lista viene sin líneas ni pagos).
Map<String, dynamic> _pedido(
  int id,
  String folio,
  String estado, {
  double total = 1600,
  double pagado = 0,
  double conVales = 0,
  double anticipoPendiente = 200,
}) => {
  'id': id,
  'folio': folio,
  'tipo': 'cliente_directo',
  'estado': estado,
  'total': total,
  'pagado': pagado,
  'pagado_con_vales': conVales,
  'saldo': total - pagado,
  'anticipo_requerido': 200.0,
  'anticipo_pagado': 200.0 - anticipoPendiente,
  'anticipo_pendiente': anticipoPendiente,
  'fecha_colocacion': '2026-09-18T10:30:00-06:00',
};

void main() {
  setUp(() => FlutterSecureStorage.setMockInitialValues(<String, String>{'token_sanctum': '1|token'}));

  Future<List<String>> abrir(WidgetTester tester, {List<Map<String, dynamic>>? lista}) async {
    final rutas = <String>[];

    final servidor = MockClient((peticion) async {
      final ruta = peticion.url.path.replaceFirst('/api', '');
      rutas.add('${peticion.method} $ruta');

      if (ruta == '/pedidos') {
        return _json({
          'data': lista ??
              [
                _pedido(3, 'PED-003', 'solicitado_fabrica'),
                _pedido(2, 'PED-002', 'borrador'),
                _pedido(1, 'PED-001', 'entregado', total: 900, pagado: 900, anticipoPendiente: 0),
              ],
        }, 200);
      }
      if (ruta == '/pedidos/3') {
        return _json({
          'data': {
            ..._pedido(3, 'PED-003', 'solicitado_fabrica', pagado: 500, conVales: 300, anticipoPendiente: 0),
            'lineas': [
              {
                'producto_nombre': 'Botín casual',
                'modelo': 'MOD-01',
                'talla': '24',
                'color': 'Negro',
                'cantidad': 2,
                'precio_unitario': 800.0,
                'subtotal': 1600.0,
              },
            ],
            'pagos': [
              {'folio': 'PAG-1', 'tipo': 'anticipo', 'metodo': 'efectivo', 'monto': 200.0, 'fecha_pago': '2026-09-18T11:00:00-06:00'},
            ],
          },
        }, 200);
      }
      return _json({'message': 'No encontrado.'}, 404);
    });

    await tester.pumpWidget(
      ChangeNotifierProvider(
        create: (_) => AuthProvider(api: ApiService(cliente: servidor)),
        child: const MaterialApp(home: MisPedidosScreen()),
      ),
    );
    await tester.pumpAndSettle();

    return rutas;
  }

  testWidgets('muestra los pedidos propios con su estado y saldo, sin borradores', (tester) async {
    final rutas = await abrir(tester);

    expect(rutas, contains('GET /pedidos'));
    expect(find.text('PED-003'), findsOneWidget);
    expect(find.text('Solicitado fábrica'), findsOneWidget);
    expect(find.text('PED-001'), findsOneWidget);
    expect(find.text('Entregado'), findsOneWidget);
    // Un borrador nunca se envió: no se muestra.
    expect(find.text('PED-002'), findsNothing);

    expect(find.text('Saldo pendiente'), findsOneWidget);
    expect(find.text(r'$1,600.00'), findsNWidgets(2));
  });

  testWidgets('sin pedidos, lo dice', (tester) async {
    await abrir(tester, lista: []);

    expect(find.text('Todavía no tienes pedidos.'), findsOneWidget);
  });

  testWidgets('el detalle explica el estado y muestra productos, vale, pagos y saldo', (tester) async {
    final rutas = await abrir(tester);

    await tester.tap(find.text('PED-003'));
    await tester.pumpAndSettle();

    expect(rutas, contains('GET /pedidos/3'));
    expect(find.text('Tu pedido ya se le pidió a la fábrica.'), findsOneWidget);
    expect(find.text('Botín casual'), findsOneWidget);
    expect(find.textContaining('2 × \$800.00'), findsOneWidget);

    // Pagado 500 = 300 con vale + 200 en mostrador; queda 1,100.
    expect(find.text('Pagado con vale'), findsOneWidget);
    expect(find.text(r'-$300.00'), findsOneWidget);
    expect(find.text('Pagado en mostrador'), findsOneWidget);
    expect(find.text(r'-$200.00'), findsOneWidget);
    expect(find.text(r'$1,100.00'), findsOneWidget);

    // El pago va hasta abajo: se desliza hasta que se dibuja.
    await tester.scrollUntilVisible(find.textContaining('Anticipo · '), 200);
    expect(find.text('Pagos registrados'), findsOneWidget);
    expect(find.textContaining('Anticipo · \$200.00'), findsOneWidget);
  });

  testWidgets('un pedido que no existe o no es suyo avisa en vez de tronar', (tester) async {
    await abrir(tester, lista: [_pedido(99, 'PED-099', 'colocado')]);

    await tester.tap(find.text('PED-099'));
    await tester.pumpAndSettle();

    expect(find.text('No encontramos ese pedido.'), findsOneWidget);
  });
}
