import 'dart:convert';

import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:footwearpoint/main.dart';

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

  testWidgets('si hay una sesión guardada, entra directo sin pedir login', (tester) async {
    FlutterSecureStorage.setMockInitialValues(<String, String>{
      'token_sanctum': '1|token-de-prueba',
      'sesion_usuario': jsonEncode({
        'usuario': {
          'id': 7,
          'nombre': 'María López',
          'email': 'maria@ejemplo.com',
          'telefono': null,
          'estado': 'activo',
        },
        'rol': 'revendedor',
        'distribuidora_id': 1,
      }),
    });

    await tester.pumpWidget(const FootwearPointApp());
    await tester.pumpAndSettle();

    expect(find.text('Sesión iniciada'), findsOneWidget);
    expect(find.text('María López'), findsOneWidget);
    expect(find.text('Entrar'), findsNothing);
  });
}
