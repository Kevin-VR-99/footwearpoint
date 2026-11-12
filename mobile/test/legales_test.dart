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
Map<String, dynamic> _sesion({required bool legales, List<Map<String, dynamic>>? pendientes}) => {
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
  'legales_pendientes': ?pendientes,
  'debe_cambiar_password': false,
};

/// TG-323: lo que manda el servidor cuando cambió la versión del aviso.
const _avisoNuevo = {
  'tipo_documento': 'aviso_privacidad',
  'nombre': 'Aviso de privacidad',
  'version': '1.3',
  'fecha_actualizacion': '20 de noviembre de 2026',
  'url': 'https://footwearpoint.app/aviso-privacidad',
};

/// TG-190: aceptación obligatoria del aviso de privacidad y los términos.
void main() {
  late List<String> rutas;

  /// Servidor falso: la sesión trae legales_aceptados en false hasta que se
  /// llama a auth/aceptar-legales, igual que el de verdad.
  late List<String> cuerpos;

  MockClient servidor({required bool legales, bool aceptarFalla = false, List<Map<String, dynamic>>? pendientes}) {
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
        return _json({'data': _sesion(legales: aceptados, pendientes: aceptados ? const [] : pendientes)}, 200);
      }
      if (ruta == '/auth/aceptar-legales') {
        cuerpos.add(peticion.body);
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

  setUp(() {
    rutas = [];
    cuerpos = [];
  });

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

  // ------------------------------------------------------------------
  // TG-323 (K24): los documentos reales y su versión
  // ------------------------------------------------------------------

  testWidgets('TG-323: muestra solo el documento que cambió, con su versión', (tester) async {
    FlutterSecureStorage.setMockInitialValues(<String, String>{'token_sanctum': '1|token'});

    await tester.pumpWidget(
      FootwearPointApp(api: ApiService(cliente: servidor(legales: false, pendientes: [_avisoNuevo]))),
    );
    await tester.pumpAndSettle();

    expect(find.text('Aviso de privacidad'), findsOneWidget);
    expect(find.text('Versión 1.3, actualizado el 20 de noviembre de 2026'), findsOneWidget);
    expect(find.text('Términos y condiciones'), findsNothing);
    expect(find.text('He leído y acepto el aviso de privacidad.'), findsOneWidget);
    expect(find.widgetWithText(TextButton, 'Leer'), findsOneWidget);
    // Ya no está el resumen propio de la app.
    expect(find.textContaining('en cumplimiento'), findsNothing);
  });

  testWidgets('TG-323: al aceptar manda solo el tipo, sin versión inventada', (tester) async {
    FlutterSecureStorage.setMockInitialValues(<String, String>{'token_sanctum': '1|token'});

    await tester.pumpWidget(
      FootwearPointApp(api: ApiService(cliente: servidor(legales: false, pendientes: [_avisoNuevo]))),
    );
    await tester.pumpAndSettle();
    await aceptar(tester);

    expect(cuerpos, hasLength(1));
    expect(jsonDecode(cuerpos.single), {
      'documentos': [
        {'tipo_documento': 'aviso_privacidad'},
      ],
    });
    expect(cuerpos.single, isNot(contains('1.0')));
    expect(find.text('Sesión iniciada'), findsOneWidget);
  });

  testWidgets('TG-323: si el servidor no dice cuáles faltan, pide los dos', (tester) async {
    FlutterSecureStorage.setMockInitialValues(<String, String>{'token_sanctum': '1|token'});

    await tester.pumpWidget(FootwearPointApp(api: ApiService(cliente: servidor(legales: false))));
    await tester.pumpAndSettle();

    expect(find.text('Aviso de privacidad'), findsOneWidget);
    expect(find.text('Términos y condiciones'), findsOneWidget);
    // Sin URL no hay botón para leer.
    expect(find.widgetWithText(TextButton, 'Leer'), findsNothing);
  });
}
