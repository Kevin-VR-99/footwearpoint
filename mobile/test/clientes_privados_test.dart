import 'dart:convert';

import 'package:flutter/material.dart';
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

/// "Mis Clientes Particulares" (E9-06).
MockClient _servidor({
  String rol = 'revendedor',
  http.Response Function()? clientes,
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
          'rol': rol,
          'legales_aceptados': true,
          'distribuidora_id': 1,
          'distribuidora': 'Calzados Ramírez',
        },
      }, 200);
    }
    if (ruta == '/revendedor/clientes-privados') {
      return clientes != null ? clientes() : _json({'data': <Object>[]}, 200);
    }
    if (ruta == '/pedidos' || ruta == '/vales') {
      return _json({'data': <Object>[]}, 200);
    }
    return _json({'message': 'Not Found'}, 404);
  });
}

void main() {
  setUp(() {
    FlutterSecureStorage.setMockInitialValues(<String, String>{
      'token_sanctum': '1|token-de-prueba',
    });
  });

  Future<void> abrir(WidgetTester tester, MockClient servidor) async {
    await tester.pumpWidget(
      FootwearPointApp(api: ApiService(cliente: servidor)),
    );
    await tester.pumpAndSettle();
    final acceso = find.text('Mis Clientes Particulares');
    await tester.ensureVisible(acceso);
    await tester.tap(acceso);
    await tester.pumpAndSettle();
  }

  testWidgets(
    'el revendedor ve su lista, con los importes que Laravel manda como texto',
    (tester) async {
      await abrir(
        tester,
        _servidor(
          clientes: () => _json({
            'data': [
              {
                'id': 3,
                'revendedor_id': 5,
                'nombre': 'Juana Pérez',
                'telefono': '9631112233',
                'producto': 'Botín negro 25',
                'monto': '1200.00',
                'saldo': '300.00',
                'referencia': null,
                'notas': null,
              },
            ],
          }, 200),
        ),
      );

      expect(find.text('Juana Pérez'), findsOneWidget);
      expect(find.text('Saldo pendiente'), findsOneWidget);
    },
  );

  testWidgets(
    'si la lista no carga, se dice y se ofrece reintentar (no "no tienes clientes")',
    (tester) async {
      var llamadas = 0;
      await abrir(
        tester,
        _servidor(
          clientes: () {
            llamadas++;
            return llamadas == 1
                ? _json({'message': 'Error del servidor.'}, 500)
                : _json({'data': <Object>[]}, 200);
          },
        ),
      );

      expect(find.text('No se pudieron cargar tus clientes'), findsOneWidget);
      expect(
        find.text('Todavía no tienes clientes particulares'),
        findsNothing,
      );

      await tester.tap(find.text('Reintentar'));
      await tester.pumpAndSettle();
      expect(llamadas, 2);
      expect(
        find.text('Todavía no tienes clientes particulares'),
        findsOneWidget,
      );
    },
  );

  testWidgets(
    'un cliente directo no ve el acceso (el servidor le contesta 403)',
    (tester) async {
      await tester.pumpWidget(
        FootwearPointApp(
          api: ApiService(cliente: _servidor(rol: 'cliente_directo')),
        ),
      );
      await tester.pumpAndSettle();

      expect(find.text('Mis Clientes Particulares'), findsNothing);
      expect(find.byType(Scaffold), findsWidgets);
    },
  );
}
