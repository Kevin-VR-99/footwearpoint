import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:footwearpoint/main.dart';
import 'package:footwearpoint/services/api_service.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

http.Response _json(Object cuerpo, int codigo) =>
    http.Response(jsonEncode(cuerpo), codigo, headers: {'content-type': 'application/json; charset=utf-8'});

/// Sesión como la manda el servidor, con o sin los legales aceptados (E1-04).
Map<String, dynamic> _sesion({required bool legales}) => {
  'usuario': {
    'id': 7,
    'nombre': 'María López',
    'email': 'maria.lopez@revendedor.test',
    'telefono': null,
    'estado': 'activo',
  },
  'rol': 'revendedor',
  'distribuidora_id': 1,
  'legales_aceptados': legales,
  'debe_cambiar_password': false,
};

/// TG-190: aceptación obligatoria del aviso de privacidad y los términos.
void main() {
  late List<String> rutas;

  /// Servidor falso: la sesión trae legales_aceptados en false hasta que se
  /// llama a auth/aceptar-legales, igual que el de verdad.
  MockClient servidor({required bool legales, bool aceptarFalla = false}) {
    var aceptados = legales;

    return MockClient((peticion) async {
      final ruta = peticion.url.path.replaceFirst('/api', '');
      rutas.add('${peticion.method} $ruta');

      if (ruta == '/auth/login') {
        return _json({
          'data': {'token': '1|nuevo', ..._sesion(legales: aceptados)},
        }, 200);
      }
      if (ruta == '/auth/me') {
        return _json({'data': _sesion(legales: aceptados)}, 200);
      }
      if (ruta == '/auth/aceptar-legales') {
        if (aceptarFalla) {
          return _json({'message': 'No se pudo registrar la aceptación.'}, 500);
        }
        aceptados = true;
        return _json({'message': 'Aceptación registrada.'}, 200);
      }
      if (ruta == '/auth/logout') {
        return _json({'message': 'Sesión cerrada.'}, 200);
      }
      return _json({'data': <Object>[]}, 200);
    });
  }

  Future<void> aceptar(WidgetTester tester) async {
    await tester.tap(find.byType(Checkbox));
    await tester.pump();
    final boton = find.widgetWithText(FilledButton, 'Aceptar y Continuar');
    await tester.ensureVisible(boton);
    await tester.tap(boton);
    await tester.pumpAndSettle();
  }

  setUp(() => rutas = []);

  testWidgets('al reabrir la app sin legales aceptados muestra el aviso y conserva la sesión', (tester) async {
    FlutterSecureStorage.setMockInitialValues(<String, String>{'token_sanctum': '1|token'});
    final api = ApiService(cliente: servidor(legales: false));

    await tester.pumpWidget(FootwearPointApp(api: api));
    await tester.pumpAndSettle();

    expect(find.text('Políticas y Privacidad'), findsOneWidget);
    expect(find.text('Sesión iniciada'), findsNothing);
    expect(await api.token(), '1|token');
  });

  testWidgets('al aceptarlos lo registra en el servidor y entra al inicio', (tester) async {
    FlutterSecureStorage.setMockInitialValues(<String, String>{'token_sanctum': '1|token'});

    await tester.pumpWidget(FootwearPointApp(api: ApiService(cliente: servidor(legales: false))));
    await tester.pumpAndSettle();
    await aceptar(tester);

    expect(rutas, contains('POST /auth/aceptar-legales'));
    expect(find.text('Sesión iniciada'), findsOneWidget);
    expect(find.text('Políticas y Privacidad'), findsNothing);
  });

  testWidgets('si el servidor falla al aceptar, se queda en el aviso', (tester) async {
    FlutterSecureStorage.setMockInitialValues(<String, String>{'token_sanctum': '1|token'});

    await tester.pumpWidget(FootwearPointApp(api: ApiService(cliente: servidor(legales: false, aceptarFalla: true))));
    await tester.pumpAndSettle();
    await aceptar(tester);

    expect(find.text('Políticas y Privacidad'), findsOneWidget);
    expect(find.text('Sesión iniciada'), findsNothing);
  });

  testWidgets('si no acepta, cierra la sesión y vuelve al login', (tester) async {
    FlutterSecureStorage.setMockInitialValues(<String, String>{'token_sanctum': '1|token'});
    final api = ApiService(cliente: servidor(legales: false));

    await tester.pumpWidget(FootwearPointApp(api: api));
    await tester.pumpAndSettle();

    final salir = find.text('No acepto, cerrar sesión');
    await tester.ensureVisible(salir);
    await tester.tap(salir);
    await tester.pumpAndSettle();

    expect(rutas, contains('POST /auth/logout'));
    expect(find.text('Políticas y Privacidad'), findsNothing);
    expect(await api.token(), isNull);
  });

  testWidgets('al iniciar sesión sin legales aceptados no entra al inicio hasta aceptarlos', (tester) async {
    FlutterSecureStorage.setMockInitialValues(<String, String>{});

    await tester.pumpWidget(FootwearPointApp(api: ApiService(cliente: servidor(legales: false))));
    await tester.pumpAndSettle();

    await tester.enterText(find.byType(TextFormField).at(0), 'maria.lopez@revendedor.test');
    await tester.enterText(find.byType(TextFormField).at(1), 'secreto123');
    await tester.tap(find.widgetWithText(FilledButton, 'Entrar'));
    await tester.pumpAndSettle();

    expect(find.text('Políticas y Privacidad'), findsOneWidget);
    expect(find.text('Sesión iniciada'), findsNothing);

    await aceptar(tester);

    expect(find.text('Sesión iniciada'), findsOneWidget);
  });

  testWidgets('con los legales ya aceptados entra directo al inicio', (tester) async {
    FlutterSecureStorage.setMockInitialValues(<String, String>{'token_sanctum': '1|token'});

    await tester.pumpWidget(FootwearPointApp(api: ApiService(cliente: servidor(legales: true))));
    await tester.pumpAndSettle();

    expect(find.text('Sesión iniciada'), findsOneWidget);
    expect(find.text('Políticas y Privacidad'), findsNothing);
  });
}
