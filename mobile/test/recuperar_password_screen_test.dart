import 'dart:convert';
import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:footwearpoint/main.dart';
import 'package:footwearpoint/providers/auth_provider.dart';
import 'package:footwearpoint/screens/recuperar_password_screen.dart';
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

  setUp(() {
    FlutterSecureStorage.setMockInitialValues(<String, String>{});
    peticiones = [];
  });

  /// La pantalla sola, con un servidor falso que responde [respuesta] a
  /// forgot-password.
  Future<void> abrirPantalla(
    WidgetTester tester,
    Future<http.Response> Function() respuesta, {
    String correoInicial = '',
  }) async {
    final servidor = MockClient((peticion) async {
      peticiones.add(peticion);
      return respuesta();
    });

    await tester.pumpWidget(
      ChangeNotifierProvider(
        create: (_) => AuthProvider(api: ApiService(cliente: servidor)),
        child: MaterialApp(
          home: RecuperarPasswordScreen(correoInicial: correoInicial),
        ),
      ),
    );
  }

  Future<void> enviar(WidgetTester tester, String correo) async {
    await tester.enterText(find.widgetWithText(TextFormField, 'Correo'), correo);
    await tester.tap(find.text('Enviar enlace'));
    await tester.pumpAndSettle();
  }

  testWidgets('el enlace del login abre la pantalla con el correo ya escrito', (tester) async {
    await tester.pumpWidget(const FootwearPointApp());
    await tester.pumpAndSettle();

    await tester.enterText(
      find.widgetWithText(TextFormField, 'Correo'),
      'maria@ejemplo.com',
    );
    await tester.tap(find.text('¿Olvidaste tu contraseña?'));
    await tester.pumpAndSettle();

    expect(find.text('Recuperar contraseña'), findsOneWidget);
    expect(find.text('maria@ejemplo.com'), findsOneWidget);
  });

  testWidgets('un correo mal escrito no se manda al servidor', (tester) async {
    await abrirPantalla(tester, () async => _json({'message': 'ok'}, 200));

    await enviar(tester, 'maria.lopez');

    expect(find.text('El correo no es válido.'), findsOneWidget);
    expect(peticiones, isEmpty);
  });

  testWidgets('al enviar bien, muestra "Revisa tu bandeja" con el texto neutro', (tester) async {
    await abrirPantalla(
      tester,
      () async => _json({'message': 'Se ha enviado el enlace de recuperación al correo.'}, 200),
    );

    await enviar(tester, 'maria@ejemplo.com');

    expect(peticiones.single.url.path, endsWith('/auth/forgot-password'));
    expect(jsonDecode(peticiones.single.body), {'email': 'maria@ejemplo.com'});
    expect(find.text('Revisa tu bandeja'), findsOneWidget);
    expect(find.text(RecuperarPasswordScreen.mensajeEnviado), findsOneWidget);
  });

  testWidgets('si el correo no tiene cuenta (422 sin errores), muestra lo mismo que un envío bueno', (tester) async {
    // Lo que responde hoy el backend cuando el correo no existe o se pidió
    // otro enlace hace menos de un minuto.
    await abrirPantalla(
      tester,
      () async => _json({
        'message': 'No se pudo enviar el enlace de recuperación.',
        'error': "We can't find a user with that email address.",
      }, 422),
    );

    await enviar(tester, 'noexiste@ejemplo.com');

    expect(find.text('Revisa tu bandeja'), findsOneWidget);
    expect(find.text(RecuperarPasswordScreen.mensajeEnviado), findsOneWidget);
    expect(find.textContaining('No se pudo'), findsNothing);
  });

  testWidgets('un error de validación del servidor se muestra', (tester) async {
    await abrirPantalla(
      tester,
      () async => _json({
        'message': 'El correo no es válido.',
        'errors': {
          'email': ['El correo no es válido.'],
        },
      }, 422),
    );

    await enviar(tester, 'maria@ejemplo.com');

    expect(find.text('El correo no es válido.'), findsOneWidget);
    expect(find.text('Revisa tu bandeja'), findsNothing);
  });

  testWidgets('sin conexión, avisa y deja volver a intentar', (tester) async {
    await abrirPantalla(tester, () async => throw const SocketException('sin red'));

    await enviar(tester, 'maria@ejemplo.com');

    expect(find.textContaining('No se pudo conectar con el servidor'), findsOneWidget);
    expect(find.text('Enviar enlace'), findsOneWidget);
    expect(find.text('Revisa tu bandeja'), findsNothing);
  });

  testWidgets('"Volver al login" regresa al login', (tester) async {
    await tester.pumpWidget(
      FootwearPointApp(
        api: ApiService(
          cliente: MockClient((_) async => _json({'message': 'ok'}, 200)),
        ),
      ),
    );
    await tester.pumpAndSettle();

    await tester.tap(find.text('¿Olvidaste tu contraseña?'));
    await tester.pumpAndSettle();
    await enviar(tester, 'maria@ejemplo.com');

    await tester.tap(find.text('Volver al login'));
    await tester.pumpAndSettle();

    expect(find.text('Entrar'), findsOneWidget);
    expect(find.text('Recuperar contraseña'), findsNothing);
  });
}
