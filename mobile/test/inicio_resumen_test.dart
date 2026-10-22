import 'dart:convert';

import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:footwearpoint/main.dart';
import 'package:footwearpoint/services/api_service.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

http.Response _json(Object cuerpo, int codigo) => http.Response(
  jsonEncode(cuerpo),
  codigo,
  headers: {'content-type': 'application/json; charset=utf-8'},
);

Map<String, dynamic> _pedido(String folio, String estado, double total, double pagado) => {
  'id': folio.hashCode.abs() % 1000,
  'folio': folio,
  'tipo': 'revendedor',
  'estado': estado,
  'total': total,
  'pagado': pagado,
  'pagado_con_vales': 0.0,
  'saldo': total - pagado,
  'anticipo_requerido': 0.0,
  'anticipo_pagado': 0.0,
  'anticipo_pendiente': 0.0,
  'fecha_colocacion': '2026-10-18T10:30:00-06:00',
};

Map<String, dynamic> _vale(String folio, double saldo) => {
  'id': folio.hashCode.abs() % 1000,
  'folio': folio,
  'monto_original': 500.0,
  'saldo_actual': saldo,
  'fecha_emision': '2026-09-01T10:00:00-06:00',
  'fecha_vencimiento': '2099-11-30T10:00:00-06:00',
  'estado': 'activo',
};

/// Lo que contaría el servidor: en curso sin borrador, entregado, rechazado
/// ni descartado, y solo los tres pedidos más nuevos que no son borrador.
Map<String, dynamic> _resumenDe(List<Map<String, dynamic>> pedidos) {
  const terminados = {'borrador', 'descartado', 'entregado', 'rechazado'};
  final enCurso = pedidos.where((p) => !terminados.contains(p['estado']));
  final porPagar = enCurso.fold<double>(0, (suma, p) => suma + (p['saldo'] as num).toDouble());
  final ultimos = pedidos.where((p) => p['estado'] != 'borrador').take(3).toList();

  return {'en_curso': enCurso.length, 'por_pagar': porPagar, 'ultimos': ultimos};
}

/// TG-281 (E19-11), punto 5: el Inicio enseña números, como el panel web.
///
/// Antes solo tenía botones para ir a otro lado. Lo que se prueba aquí es la
/// cuenta: qué entra en cada cifra y qué no.
MockClient _servidor({
  List<Map<String, dynamic>>? pedidos,
  List<Map<String, dynamic>>? vales,
  bool pedidosFallan = false,
}) {
  return MockClient((peticion) async {
    final ruta = peticion.url.path.replaceFirst('/api', '');

    if (ruta == '/auth/me') {
      return _json({
        'data': {
          'usuario': {
            'id': 7,
            'nombre': 'María López',
            'email': 'maria@ejemplo.com',
            'telefono': null,
            'estado': 'activo',
          },
          'rol': 'revendedor',
          'legales_aceptados': true,
          'distribuidora_id': 1,
          'distribuidora': 'Calzados Ramírez',
        },
      }, 200);
    }

    if (ruta == '/pedidos/resumen') {
      if (pedidosFallan) return _json({'message': 'Error del servidor.'}, 500);

      return _json({'data': _resumenDe(pedidos ?? const [])}, 200);
    }

    if (ruta == '/vales') {
      return _json({'data': vales ?? const <Object>[]}, 200);
    }

    return _json({'data': <Object>[]}, 200);
  });
}

void main() {
  setUp(() => FlutterSecureStorage.setMockInitialValues(<String, String>{'token_sanctum': '1|token'}));

  Future<void> abrir(WidgetTester tester, MockClient cliente) async {
    await tester.pumpWidget(FootwearPointApp(api: ApiService(cliente: cliente)));
    await tester.pumpAndSettle();
  }

  testWidgets('cuenta los pedidos en curso y deja fuera los que ya terminaron', (tester) async {
    await abrir(
      tester,
      _servidor(
        pedidos: [
          _pedido('PED-001', 'colocado', 1500, 0),
          _pedido('PED-002', 'listo_entrega', 980.50, 500),
          // Estos dos ya se acabaron: no cuentan.
          _pedido('PED-003', 'entregado', 700, 700),
          _pedido('PED-004', 'rechazado', 300, 0),
        ],
      ),
    );

    expect(find.text('Pedidos en curso'), findsOneWidget);
    expect(find.text('2'), findsOneWidget);
  });

  testWidgets('suma lo que falta pagar solo de los pedidos en curso', (tester) async {
    await abrir(
      tester,
      _servidor(
        pedidos: [
          _pedido('PED-001', 'colocado', 1500, 0),
          _pedido('PED-002', 'listo_entrega', 980.50, 500),
          // Entregado y sin pagar: aun asi no entra en la cuenta, porque la
          // cifra dice "de tus pedidos en curso".
          _pedido('PED-003', 'entregado', 700, 0),
        ],
      ),
    );

    expect(find.text(r'$1,980.50'), findsOneWidget);
  });

  testWidgets('suma el saldo de los vales y dice cuántos son', (tester) async {
    await abrir(
      tester,
      _servidor(vales: [_vale('VAL-0001', 300), _vale('VAL-0002', 150.25)]),
    );

    expect(find.text(r'$450.25'), findsOneWidget);
    expect(find.text('2 vales vigentes'), findsOneWidget);
  });

  testWidgets('sin pedidos ni vales enseña ceros y no la sección de últimos pedidos', (tester) async {
    await abrir(tester, _servidor());

    expect(find.text('0'), findsOneWidget);
    expect(find.text('0 vales vigentes'), findsOneWidget);
    expect(find.text('Tus últimos pedidos'), findsNothing);
  });

  testWidgets('enseña los tres pedidos más recientes y el enlace a todos', (tester) async {
    await abrir(
      tester,
      _servidor(
        pedidos: [
          _pedido('PED-001', 'colocado', 1500, 0),
          _pedido('PED-002', 'listo_entrega', 980.50, 500),
          _pedido('PED-003', 'entregado', 700, 700),
          _pedido('PED-004', 'colocado', 100, 0),
        ],
      ),
    );

    expect(find.text('Tus últimos pedidos'), findsOneWidget);
    expect(find.text('Ver todos'), findsOneWidget);
    expect(find.text('PED-001'), findsOneWidget);
    expect(find.text('PED-003'), findsOneWidget);
    // Solo tres: el resto esta a un toque en la pestaña de pedidos.
    expect(find.text('PED-004'), findsNothing);
  });

  /// El resumen es de adorno: si no se puede armar, el Inicio tiene que
  /// seguir sirviendo para navegar.
  testWidgets('si el servidor falla, el Inicio sigue en pie con un guion', (tester) async {
    await abrir(tester, _servidor(pedidosFallan: true));

    expect(find.text('Hola, María'), findsOneWidget);
    expect(find.text('Tu resumen'), findsOneWidget);
    expect(find.text('—'), findsWidgets);
    // Y la barra de abajo sigue ahí.
    expect(find.text('Catálogo'), findsOneWidget);
  });
}
