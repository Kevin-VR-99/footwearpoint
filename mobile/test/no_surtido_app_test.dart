import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:footwearpoint/providers/auth_provider.dart';
import 'package:footwearpoint/screens/pedido_detalle_screen.dart';
import 'package:footwearpoint/services/api_service.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:provider/provider.dart';

http.Response _json(Object cuerpo, int codigo) => http.Response(
  jsonEncode(cuerpo),
  codigo,
  headers: {'content-type': 'application/json; charset=utf-8'},
);

/// Un renglón del pedido, como lo manda PedidoResource.
Map<String, dynamic> _linea({
  int id = 31,
  String nombre = 'Botín casual',
  String estadoSurtido = 'surtido',
  String? motivo,
  String? resolucion,
}) => {
  'id': id,
  'producto_nombre': nombre,
  'modelo': 'MOD-01',
  'talla': '24',
  'color': 'Negro',
  'cantidad': 2,
  'precio_unitario': 800.0,
  'subtotal': 1600.0,
  'estado_surtido': estadoSurtido,
  'motivo_no_surtido': motivo,
  'resolucion_no_surtido': resolucion,
};

Map<String, dynamic> _pedido(List<Map<String, dynamic>> lineas) => {
  'id': 60,
  'folio': 'PED-20261010-0007',
  'tipo': 'cliente_directo',
  'estado': 'recibido_distribuidora',
  'total': 1600.0,
  'pagado': 200.0,
  'pagado_con_vales': 0.0,
  'saldo': 1400.0,
  'anticipo_requerido': 200.0,
  'anticipo_pagado': 200.0,
  'anticipo_pendiente': 0.0,
  'fecha_colocacion': '2026-10-01T10:30:00-06:00',
  'lineas': lineas,
  'pagos': <Object>[],
};

/// TG-241 (A12) en la app: cuando la fábrica no surtió algo, la persona elige
/// aquí mismo entre que se lo cambien o que le devuelvan su anticipo.
///
/// La notificación la manda justo a esta pantalla, así que si aquí no hay
/// nada que tocar, la decisión nunca llega.
class _Servidor {
  _Servidor({required this.lineas, this.resolucionFalla = false});

  final List<Map<String, dynamic>> lineas;
  final bool resolucionFalla;
  final peticiones = <http.Request>[];

  /// Lo que la persona eligió; a partir de ahí el servidor ya lo devuelve así.
  String? elegido;

  MockClient get cliente => MockClient((peticion) async {
    peticiones.add(peticion);
    final ruta = peticion.url.path.replaceFirst('/api', '');

    if (ruta == '/pedidos/60/detalle/31/resolucion' && peticion.method == 'POST') {
      if (resolucionFalla) {
        return _json({
          'message': 'Ese renglón ya se resolvió. Si hay que cambiarlo, avisa a la distribuidora.',
        }, 422);
      }

      final cuerpo = jsonDecode(peticion.body) as Map<String, dynamic>;
      elegido = cuerpo['resolucion'] as String?;

      return _json({'data': {}, 'message': 'Listo, ya quedó anotado lo que prefieres.'}, 200);
    }

    if (ruta == '/pedidos/60' && peticion.method == 'GET') {
      return _json({
        'data': _pedido([
          for (final linea in lineas)
            if (elegido != null && linea['estado_surtido'] == 'no_surtido')
              {...linea, 'resolucion_no_surtido': elegido}
            else
              linea,
        ]),
      }, 200);
    }

    return _json({'message': 'No encontrado.'}, 404);
  });

  List<Map<String, dynamic>> get resoluciones => [
    for (final p in peticiones)
      if (p.url.path.endsWith('/resolucion')) jsonDecode(p.body) as Map<String, dynamic>,
  ];
}

void main() {
  setUp(() => FlutterSecureStorage.setMockInitialValues(<String, String>{'token_sanctum': '1|token'}));

  Future<void> abrir(WidgetTester tester, _Servidor servidor) async {
    await tester.pumpWidget(
      ChangeNotifierProvider(
        create: (_) => AuthProvider(api: ApiService(cliente: servidor.cliente)),
        child: const MaterialApp(home: PedidoDetalleScreen(pedidoId: 60)),
      ),
    );
    await tester.pumpAndSettle();
  }

  testWidgets('si todo se surtió, no se le pregunta nada', (tester) async {
    await abrir(tester, _Servidor(lineas: [_linea()]));

    expect(find.text('No llegó de fábrica'), findsNothing);
    expect(find.text('¿Qué prefieres?'), findsNothing);
  });

  testWidgets('con un renglón no surtido, dice cuál es, por qué, y ofrece las dos opciones', (tester) async {
    await abrir(
      tester,
      _Servidor(
        lineas: [
          _linea(),
          _linea(
            id: 31,
            nombre: 'Sandalia playera',
            estadoSurtido: 'no_surtido',
            motivo: 'La fábrica ya no tiene esa talla.',
            resolucion: 'pendiente',
          ),
        ],
      ),
    );

    expect(find.text('No llegó de fábrica'), findsOneWidget);
    expect(find.text('La fábrica ya no tiene esa talla.'), findsOneWidget);
    expect(find.text('¿Qué prefieres?'), findsOneWidget);
    expect(find.text('Que me lo cambien por otro'), findsOneWidget);
    expect(find.text('Que me devuelvan mi anticipo'), findsOneWidget);
  });

  testWidgets('elegir el cambio manda la decisión y deja de preguntar', (tester) async {
    final servidor = _Servidor(
      lineas: [
        _linea(
          nombre: 'Sandalia playera',
          estadoSurtido: 'no_surtido',
          motivo: 'La fábrica ya no tiene esa talla.',
          resolucion: 'pendiente',
        ),
      ],
    );
    await abrir(tester, servidor);

    await tester.ensureVisible(find.text('Que me lo cambien por otro'));
    await tester.tap(find.text('Que me lo cambien por otro'));
    await tester.pumpAndSettle();

    expect(servidor.resoluciones, [
      {'resolucion': 'sustitucion'},
    ]);
    expect(find.text('Listo, ya quedó anotado lo que prefieres.'), findsOneWidget);

    // Ya eligió: la pantalla se lo confirma y no le vuelve a preguntar.
    expect(find.text('¿Qué prefieres?'), findsNothing);
    expect(find.textContaining('Pediste que te lo cambien por otro producto'), findsOneWidget);
  });

  testWidgets('elegir la devolución del anticipo manda esa decisión', (tester) async {
    final servidor = _Servidor(
      lineas: [
        _linea(
          nombre: 'Sandalia playera',
          estadoSurtido: 'no_surtido',
          motivo: 'La fábrica ya no tiene esa talla.',
          resolucion: 'pendiente',
        ),
      ],
    );
    await abrir(tester, servidor);

    await tester.ensureVisible(find.text('Que me devuelvan mi anticipo'));
    await tester.tap(find.text('Que me devuelvan mi anticipo'));
    await tester.pumpAndSettle();

    expect(servidor.resoluciones, [
      {'resolucion': 'reembolso_anticipo'},
    ]);
    expect(find.textContaining('Pediste que te devuelvan tu anticipo'), findsOneWidget);
  });

  testWidgets('si ya estaba resuelto, solo se lo recuerda sin botones', (tester) async {
    await abrir(
      tester,
      _Servidor(
        lineas: [
          _linea(
            nombre: 'Sandalia playera',
            estadoSurtido: 'no_surtido',
            motivo: 'La fábrica ya no tiene esa talla.',
            resolucion: 'reembolso_anticipo',
          ),
        ],
      ),
    );

    expect(find.text('No llegó de fábrica'), findsOneWidget);
    expect(find.text('¿Qué prefieres?'), findsNothing);
    expect(find.textContaining('Pediste que te devuelvan tu anticipo'), findsOneWidget);
  });

  testWidgets('si el servidor rechaza la decisión, se lo dice con sus palabras', (tester) async {
    final servidor = _Servidor(
      resolucionFalla: true,
      lineas: [
        _linea(
          nombre: 'Sandalia playera',
          estadoSurtido: 'no_surtido',
          motivo: 'La fábrica ya no tiene esa talla.',
          resolucion: 'pendiente',
        ),
      ],
    );
    await abrir(tester, servidor);

    await tester.ensureVisible(find.text('Que me lo cambien por otro'));
    await tester.tap(find.text('Que me lo cambien por otro'));
    await tester.pumpAndSettle();

    expect(find.textContaining('Ese renglón ya se resolvió'), findsOneWidget);
    // Sigue en la pantalla, con sus botones: puede volver a intentar.
    expect(find.text('¿Qué prefieres?'), findsOneWidget);
  });
}
