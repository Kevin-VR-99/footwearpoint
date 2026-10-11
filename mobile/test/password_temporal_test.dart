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

/// Sesión como la manda el servidor, con o sin contraseña temporal (TG-193).
Map<String, dynamic> _sesion({required bool debeCambiar}) => {
  'usuario': {
    'id': 7,
    'nombre': 'María López',
    'email': 'maria.lopez@revendedor.test',
    'telefono': null,
    'estado': 'activo',
  },
  'rol': 'revendedor',
  'distribuidora_id': 1,
  'debe_cambiar_password': debeCambiar,
};

void main() {
  setUp(() {
    FlutterSecureStorage.setMockInitialValues(<String, String>{'token_sanctum': '1|token'});
  });

  /// Servidor falso: la sesión trae la bandera hasta que se cambia la
  /// contraseña, igual que el de verdad.
  MockClient servidor({required bool debeCambiar, bool cambioFalla = false}) {
    var pendiente = debeCambiar;

    return MockClient((peticion) async {
      final ruta = peticion.url.path.replaceFirst('/api', '');

      if (ruta == '/auth/me') {
        return _json({'data': _sesion(debeCambiar: pendiente)}, 200);
      }
      if (ruta == '/perfil/password') {
        if (cambioFalla) {
          return _json({
            'message': 'La contraseña actual no es correcta.',
            'errors': {'password_actual': ['La contraseña actual no es correcta.']},
          }, 422);
        }
        pendiente = false;
        return _json({'data': {}, 'message': 'Contraseña actualizada.'}, 200);
      }
      return _json({'data': <Object>[]}, 200);
    });
  }

  testWidgets('con contraseña temporal, la app abre directo en cambiar contraseña', (tester) async {
    await tester.pumpWidget(FootwearPointApp(api: ApiService(cliente: servidor(debeCambiar: true))));
    await tester.pumpAndSettle();

    expect(find.text('Cambiar contraseña'), findsWidgets);
    expect(find.textContaining('contraseña temporal'), findsOneWidget);
    // No llega al inicio ni puede irse a otra pantalla.
    expect(find.text('Sesión iniciada'), findsNothing);
    expect(find.text('Ver catálogo'), findsNothing);
  });

  testWidgets('sin contraseña temporal entra normal al inicio', (tester) async {
    await tester.pumpWidget(FootwearPointApp(api: ApiService(cliente: servidor(debeCambiar: false))));
    await tester.pumpAndSettle();

    expect(find.text('Sesión iniciada'), findsOneWidget);
    expect(find.textContaining('contraseña temporal'), findsNothing);
  });

  testWidgets('al cambiarla, la app pasa sola a la pantalla de inicio', (tester) async {
    await tester.pumpWidget(FootwearPointApp(api: ApiService(cliente: servidor(debeCambiar: true))));
    await tester.pumpAndSettle();

    await tester.enterText(find.widgetWithText(TextFormField, 'Contraseña actual'), 'Temporal12');
    await tester.enterText(find.widgetWithText(TextFormField, 'Nueva contraseña'), 'miClaveNueva1');
    await tester.enterText(find.widgetWithText(TextFormField, 'Confirma la nueva contraseña'), 'miClaveNueva1');

    await tester.tap(find.widgetWithText(FilledButton, 'Cambiar contraseña'));
    await tester.pumpAndSettle();

    expect(find.text('Sesión iniciada'), findsOneWidget);
  });

  testWidgets('si el servidor rechaza el cambio, se queda en la pantalla', (tester) async {
    await tester.pumpWidget(
      FootwearPointApp(api: ApiService(cliente: servidor(debeCambiar: true, cambioFalla: true))),
    );
    await tester.pumpAndSettle();

    await tester.enterText(find.widgetWithText(TextFormField, 'Contraseña actual'), 'equivocada');
    await tester.enterText(find.widgetWithText(TextFormField, 'Nueva contraseña'), 'miClaveNueva1');
    await tester.enterText(find.widgetWithText(TextFormField, 'Confirma la nueva contraseña'), 'miClaveNueva1');

    await tester.tap(find.widgetWithText(FilledButton, 'Cambiar contraseña'));
    await tester.pumpAndSettle();

    expect(find.text('La contraseña actual no es correcta.'), findsOneWidget);
    expect(find.text('Sesión iniciada'), findsNothing);
  });
}
