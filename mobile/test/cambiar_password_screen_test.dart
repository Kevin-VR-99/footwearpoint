import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:footwearpoint/providers/auth_provider.dart';
import 'package:footwearpoint/screens/cambiar_password_screen.dart';
import 'package:footwearpoint/services/api_service.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:provider/provider.dart';

http.Response _json(Object cuerpo, int codigo) => http.Response(
  jsonEncode(cuerpo),
  codigo,
  headers: {'content-type': 'application/json; charset=utf-8'},
);

void main() {
  late List<http.Request> peticiones;
  String? resultado;

  setUp(() {
    FlutterSecureStorage.setMockInitialValues(<String, String>{
      'token_sanctum': '1|token-de-prueba',
    });
    peticiones = [];
    resultado = null;
  });

  /// Una pantalla "anterior" con un botón que abre la de cambiar contraseña,
  /// para poder revisar con qué mensaje regresa.
  Future<void> abrirPantalla(
    WidgetTester tester,
    http.Response Function() respuesta,
  ) async {
    final servidor = MockClient((peticion) async {
      peticiones.add(peticion);
      return respuesta();
    });

    await tester.pumpWidget(
      ChangeNotifierProvider(
        create: (_) => AuthProvider(api: ApiService(cliente: servidor)),
        child: MaterialApp(
          home: Builder(
            builder: (context) => Scaffold(
              body: TextButton(
                onPressed: () async {
                  resultado = await Navigator.of(context).push<String>(
                    MaterialPageRoute(builder: (_) => const CambiarPasswordScreen()),
                  );
                },
                child: const Text('abrir'),
              ),
            ),
          ),
        ),
      ),
    );

    await tester.tap(find.text('abrir'));
    await tester.pumpAndSettle();
  }

  Future<void> llenar(
    WidgetTester tester, {
    String actual = 'password',
    String nueva = 'nuevaClave123',
    String? confirmacion,
  }) async {
    Finder campo(String etiqueta) => find.widgetWithText(TextFormField, etiqueta);

    await tester.enterText(campo('Contraseña actual'), actual);
    await tester.enterText(campo('Nueva contraseña'), nueva);
    await tester.enterText(campo('Confirma la nueva contraseña'), confirmacion ?? nueva);
    await tester.tap(find.widgetWithText(FilledButton, 'Cambiar contraseña'));
    await tester.pumpAndSettle();
  }

  testWidgets('una contraseña nueva corta no se manda al servidor', (tester) async {
    await abrirPantalla(tester, () => _json({'message': 'ok'}, 200));

    await llenar(tester, nueva: 'corta');

    expect(find.text('La contraseña debe tener al menos 8 caracteres.'), findsOneWidget);
    expect(peticiones, isEmpty);
  });

  testWidgets('si la confirmación no coincide no se manda al servidor', (tester) async {
    await abrirPantalla(tester, () => _json({'message': 'ok'}, 200));

    await llenar(tester, confirmacion: 'otraClave123');

    expect(find.text('Las contraseñas no coinciden.'), findsOneWidget);
    expect(peticiones, isEmpty);
  });

  testWidgets('con la contraseña actual incorrecta, el error sale en ese campo', (tester) async {
    await abrirPantalla(
      tester,
      () => _json({
        'message': 'La contraseña actual no es correcta.',
        'errors': {
          'password_actual': ['La contraseña actual no es correcta.'],
        },
      }, 422),
    );

    await llenar(tester, actual: 'no-es-esta');

    expect(find.text('La contraseña actual no es correcta.'), findsOneWidget);
    // Sigue en la pantalla para corregir.
    expect(find.text('Nueva contraseña'), findsOneWidget);
    expect(resultado, isNull);
  });

  testWidgets('al cambiarla bien manda los tres campos y regresa con el mensaje', (tester) async {
    const mensaje = 'Contraseña actualizada. Se cerró la sesión en tus otros dispositivos.';
    await abrirPantalla(tester, () => _json({'message': mensaje}, 200));

    await llenar(tester);

    final peticion = peticiones.single;
    expect(peticion.method, 'POST');
    expect(peticion.url.path, endsWith('/perfil/password'));
    expect(jsonDecode(peticion.body), {
      'password_actual': 'password',
      'password': 'nuevaClave123',
      'password_confirmation': 'nuevaClave123',
    });

    expect(resultado, mensaje);
    expect(find.text('abrir'), findsOneWidget);
  });

  testWidgets('cada campo tiene su propio botón para ver la contraseña', (tester) async {
    await abrirPantalla(tester, () => _json({'message': 'ok'}, 200));

    expect(find.byTooltip('Mostrar contraseña'), findsNWidgets(3));

    await tester.tap(find.byTooltip('Mostrar contraseña').first);
    await tester.pump();

    expect(find.byTooltip('Ocultar contraseña'), findsOneWidget);
    expect(find.byTooltip('Mostrar contraseña'), findsNWidgets(2));
  });
}
