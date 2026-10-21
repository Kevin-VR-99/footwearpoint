import 'dart:convert';
import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:footwearpoint/main.dart';
import 'package:footwearpoint/services/api_service.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// TG-281: abrir la app sin internet.
///
/// Hasta ahora, sin conexión la app se quedaba en una pantalla de error y no
/// se podía hacer nada. Ahora, si hay una copia de la última sesión, el
/// inicio y el perfil se abren con ella.
///
/// Lo que se prueba aquí es dónde está el límite: con copia se entra, sin
/// copia no, y siempre se avisa de que lo que se ve puede estar viejo.
Map<String, dynamic> get _sesion => {
  'usuario': {
    'id': 7,
    'nombre': 'María López',
    'email': 'maria@ejemplo.com',
    'telefono': '9635551111',
    'estado': 'activo',
  },
  'rol': 'revendedor',
  'legales_aceptados': true,
  'distribuidora_id': 1,
  'distribuidora': 'Calzados Ramírez',
  'debe_cambiar_password': false,
  'email_verificado': true,
};

/// Un teléfono sin red: cualquier petición revienta como en la vida real.
final _sinRed = MockClient((_) async => throw const SocketException('sin red'));

http.Response _json(Object cuerpo, int codigo) => http.Response(
  jsonEncode(cuerpo),
  codigo,
  headers: {'content-type': 'application/json; charset=utf-8'},
);

void main() {
  testWidgets('con copia guardada, la app abre y avisa de que puede estar viejo', (tester) async {
    FlutterSecureStorage.setMockInitialValues(<String, String>{
      'token_sanctum': '1|token',
      'sesion_cache': jsonEncode(_sesion),
    });

    await tester.pumpWidget(FootwearPointApp(api: ApiService(cliente: _sinRed)));
    await tester.pumpAndSettle();

    // El inicio, con su saludo y la barra de abajo.
    expect(find.text('Hola, María'), findsOneWidget);
    expect(find.text('Catálogo'), findsOneWidget);

    // Y la franja que lo dice claro.
    expect(find.text('Sin conexión. Estás viendo lo último que se guardó.'), findsOneWidget);
  });

  testWidgets('el perfil también se abre, con los datos guardados', (tester) async {
    FlutterSecureStorage.setMockInitialValues(<String, String>{
      'token_sanctum': '1|token',
      'sesion_cache': jsonEncode(_sesion),
    });

    await tester.pumpWidget(FootwearPointApp(api: ApiService(cliente: _sinRed)));
    await tester.pumpAndSettle();

    await tester.tap(find.text('Perfil'));
    await tester.pumpAndSettle();

    // GET /perfil falla por falta de red, pero no se enseña un error: se
    // enseñan los datos que ya se tenían.
    expect(find.text('María López'), findsOneWidget);
    expect(find.text('Calzados Ramírez'), findsOneWidget);
    // Y el formulario, no la pantalla de error. (El "Reintentar" que sí hay
    // es el de la franja de sin conexión de arriba, que es otra cosa.)
    expect(find.text('Guardar cambios'), findsOneWidget);
    expect(find.byIcon(Icons.person_off_outlined), findsNothing);
  });

  testWidgets('sin copia guardada se queda en la pantalla de sin conexión', (tester) async {
    FlutterSecureStorage.setMockInitialValues(<String, String>{'token_sanctum': '1|token'});

    await tester.pumpWidget(FootwearPointApp(api: ApiService(cliente: _sinRed)));
    await tester.pumpAndSettle();

    expect(find.text('No hay conexión'), findsOneWidget);
    expect(find.text('Hola, María'), findsNothing);
  });

  testWidgets('en cuanto hay internet, la copia se reemplaza por lo del servidor', (tester) async {
    FlutterSecureStorage.setMockInitialValues(<String, String>{
      'token_sanctum': '1|token',
      // Una copia vieja: el nombre cambió desde entonces.
      'sesion_cache': jsonEncode({
        ..._sesion,
        'usuario': {..._sesion['usuario'] as Map<String, dynamic>, 'nombre': 'Nombre viejo'},
      }),
    });

    final conRed = MockClient((peticion) async {
      if (peticion.url.path.endsWith('/auth/me')) return _json({'data': _sesion}, 200);

      return _json({'data': <Object>[]}, 200);
    });

    await tester.pumpWidget(FootwearPointApp(api: ApiService(cliente: conRed)));
    await tester.pumpAndSettle();

    // Manda el servidor, no la copia.
    expect(find.text('Hola, María'), findsOneWidget);
    expect(find.text('Hola, Nombre'), findsNothing);
    expect(find.text('Sin conexión. Estás viendo lo último que se guardó.'), findsNothing);
  });
}
