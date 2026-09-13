import 'dart:convert';
import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:footwearpoint/main.dart';
import 'package:footwearpoint/services/api_service.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

void main() {
  // En las pruebas no hay teléfono: el almacenamiento seguro se simula con un
  // mapa en memoria. Sin esto, leer la sesión guardada al arrancar truena.
  setUp(() => FlutterSecureStorage.setMockInitialValues(<String, String>{}));

  testWidgets('la pantalla de login muestra sus campos y el botón', (tester) async {
    await tester.pumpWidget(const FootwearPointApp());
    await tester.pumpAndSettle();

    expect(find.text('Correo'), findsOneWidget);
    expect(find.text('Contraseña'), findsOneWidget);
    expect(find.text('Entrar'), findsOneWidget);
  });

  testWidgets('con los campos vacíos no intenta entrar y avisa qué falta', (tester) async {
    await tester.pumpWidget(const FootwearPointApp());
    await tester.pumpAndSettle();

    await tester.tap(find.text('Entrar'));
    await tester.pump();

    // Si la validación no frenara aquí, la prueba intentaría salir a la red.
    expect(find.text('Escribe tu correo.'), findsOneWidget);
    expect(find.text('Escribe tu contraseña.'), findsOneWidget);
  });

  testWidgets('un correo sin forma de correo no se manda al servidor', (tester) async {
    await tester.pumpWidget(const FootwearPointApp());
    await tester.pumpAndSettle();

    await tester.enterText(find.widgetWithText(TextFormField, 'Correo'), 'maria.lopez');
    await tester.enterText(find.widgetWithText(TextFormField, 'Contraseña'), 'secreto123');
    await tester.tap(find.text('Entrar'));
    await tester.pump();

    expect(find.text('El correo no es válido.'), findsOneWidget);
  });

  testWidgets('el botón del ojo muestra y oculta la contraseña', (tester) async {
    await tester.pumpWidget(const FootwearPointApp());
    await tester.pumpAndSettle();

    bool oculta() => tester
        .widget<TextField>(find.widgetWithText(TextField, 'Contraseña'))
        .obscureText;

    expect(oculta(), isTrue);

    await tester.tap(find.byTooltip('Mostrar contraseña'));
    await tester.pump();
    expect(oculta(), isFalse);

    await tester.tap(find.byTooltip('Ocultar contraseña'));
    await tester.pump();
    expect(oculta(), isTrue);
  });

  testWidgets('con token válido (auth/me responde 200), entra directo sin pedir login', (tester) async {
    FlutterSecureStorage.setMockInitialValues(<String, String>{
      'token_sanctum': '1|token-de-prueba',
    });

    final servidor = MockClient((peticion) async => http.Response(
      jsonEncode({
        'data': {
          'usuario': {
            'id': 7,
            'nombre': 'María López',
            'email': 'maria@ejemplo.com',
            'telefono': null,
            'estado': 'activo',
          },
          'rol': 'revendedor',
          'distribuidora_id': 1,
        },
      }),
      200,
      headers: {'content-type': 'application/json; charset=utf-8'},
    ));

    await tester.pumpWidget(FootwearPointApp(api: ApiService(cliente: servidor)));
    await tester.pumpAndSettle();

    expect(find.text('Sesión iniciada'), findsOneWidget);
    expect(find.text('María López'), findsOneWidget);
    expect(find.text('Entrar'), findsNothing);
  });

  testWidgets('afiliación suspendida: no pasa de "sin acceso" y puede cerrar sesión', (tester) async {
    FlutterSecureStorage.setMockInitialValues(<String, String>{
      'token_sanctum': '1|token-de-prueba',
    });

    final servidor = MockClient((peticion) async {
      if (peticion.url.path.endsWith('/auth/logout')) {
        return http.Response(jsonEncode({'message': 'Sesión cerrada correctamente.'}), 200);
      }

      return http.Response(
        jsonEncode({
          'data': {
            'usuario': {
              'id': 8,
              'nombre': 'Revendedor Suspendido',
              'email': 'suspendido@ejemplo.com',
              'telefono': null,
              'estado': 'activo',
            },
            'rol': null,
            'distribuidora_id': null,
          },
        }),
        200,
        headers: {'content-type': 'application/json; charset=utf-8'},
      );
    });

    await tester.pumpWidget(FootwearPointApp(api: ApiService(cliente: servidor)));
    await tester.pumpAndSettle();

    expect(find.text('Sin acceso por ahora'), findsOneWidget);
    expect(find.text('Sesión iniciada'), findsNothing);

    await tester.tap(find.text('Cerrar sesión'));
    await tester.pumpAndSettle();

    expect(find.text('Entrar'), findsOneWidget);
  });

  testWidgets('con token pero sin conexión, ofrece reintentar en vez de sacar al login', (tester) async {
    FlutterSecureStorage.setMockInitialValues(<String, String>{
      'token_sanctum': '1|token-de-prueba',
    });

    final servidor = MockClient((_) async => throw const SocketException('sin red'));

    await tester.pumpWidget(FootwearPointApp(api: ApiService(cliente: servidor)));
    await tester.pumpAndSettle();

    expect(find.text('No se pudo conectar con el servidor'), findsOneWidget);
    expect(find.text('Reintentar'), findsOneWidget);
    expect(find.text('Entrar'), findsNothing);
  });
}
