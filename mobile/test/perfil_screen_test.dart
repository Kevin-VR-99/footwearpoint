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

/// Un servidor falso con una sesión de María ya iniciada. Guarda lo que se
/// le pidió para revisarlo, y cada prueba decide qué responde el PATCH.
class _ServidorFalso {
  _ServidorFalso({this.alGuardar});

  final http.Response Function(Map<String, dynamic> cuerpo)? alGuardar;
  final peticiones = <http.Request>[];

  Map<String, dynamic> usuario = {
    'id': 7,
    'nombre': 'María López',
    'email': 'maria.lopez@revendedor.test',
    'telefono': '9635551111',
    'estado': 'activo',
  };

  MockClient get cliente => MockClient((peticion) async {
    peticiones.add(peticion);
    final ruta = peticion.url.path;

    if (ruta.endsWith('/auth/me')) {
      return _json({
        'data': {'usuario': usuario, 'rol': 'revendedor', 'distribuidora_id': 1},
      }, 200);
    }

    if (ruta.endsWith('/perfil') && peticion.method == 'GET') {
      return _json({'data': usuario}, 200);
    }

    if (ruta.endsWith('/perfil') && peticion.method == 'PATCH') {
      final cuerpo = jsonDecode(peticion.body) as Map<String, dynamic>;
      if (alGuardar != null) return alGuardar!(cuerpo);

      usuario = {...usuario, 'nombre': cuerpo['nombre'], 'telefono': cuerpo['telefono']};
      return _json({'data': usuario, 'message': 'Datos actualizados correctamente.'}, 200);
    }

    return _json({'message': 'Not Found'}, 404);
  });

  List<http.Request> pedidas(String metodo, String rutaTermina) => peticiones
      .where((p) => p.method == metodo && p.url.path.endsWith(rutaTermina))
      .toList();
}

void main() {
  setUp(() {
    FlutterSecureStorage.setMockInitialValues(<String, String>{
      'token_sanctum': '1|token-de-prueba',
    });
  });

  /// Abre la app con sesión y entra a "Mi perfil" desde la pantalla de inicio.
  Future<void> abrirPerfil(WidgetTester tester, _ServidorFalso servidor) async {
    await tester.pumpWidget(FootwearPointApp(api: ApiService(cliente: servidor.cliente)));
    await tester.pumpAndSettle();

    // En pantallas chicas el inicio se desliza: primero se lleva el botón a la vista.
    await tester.ensureVisible(find.text('Mi perfil'));
    await tester.tap(find.text('Mi perfil'));
    await tester.pumpAndSettle();
  }

  Finder campo(String etiqueta) => find.widgetWithText(TextFormField, etiqueta);

  testWidgets('al abrir pide los datos al servidor y los muestra', (tester) async {
    final servidor = _ServidorFalso();
    // El servidor tiene un dato más nuevo que el que llegó en auth/me.
    await tester.pumpWidget(FootwearPointApp(api: ApiService(cliente: servidor.cliente)));
    await tester.pumpAndSettle();
    servidor.usuario = {...servidor.usuario, 'telefono': '9630000000'};

    // En pantallas chicas el inicio se desliza: primero se lleva el botón a la vista.
    await tester.ensureVisible(find.text('Mi perfil'));
    await tester.tap(find.text('Mi perfil'));
    await tester.pumpAndSettle();

    expect(servidor.pedidas('GET', '/perfil'), hasLength(1));
    expect(find.text('maria.lopez@revendedor.test'), findsOneWidget);
    expect(find.text('María López'), findsOneWidget);
    expect(find.text('9630000000'), findsOneWidget);
  });

  testWidgets('el correo se ve pero no se puede editar', (tester) async {
    await abrirPerfil(tester, _ServidorFalso());

    final correo = tester.widget<TextField>(find.widgetWithText(TextField, 'Correo'));
    expect(correo.enabled, isFalse);
    expect(find.text('El correo no se puede cambiar.'), findsOneWidget);
  });

  testWidgets('guardar manda nombre y teléfono, y el nombre nuevo se ve en la app', (tester) async {
    final servidor = _ServidorFalso();
    await abrirPerfil(tester, servidor);

    await tester.enterText(campo('Nombre'), '  María López Ruiz ');
    await tester.enterText(campo('Teléfono (opcional)'), '9630001111');
    await tester.tap(find.text('Guardar cambios'));
    await tester.pumpAndSettle();

    final patch = servidor.pedidas('PATCH', '/perfil').single;
    expect(jsonDecode(patch.body), {'nombre': 'María López Ruiz', 'telefono': '9630001111'});
    expect(find.text('Datos actualizados correctamente.'), findsOneWidget);

    // De regreso en inicio, ya con el nombre nuevo.
    await tester.pageBack();
    await tester.pumpAndSettle();
    expect(find.text('María López Ruiz'), findsOneWidget);
  });

  testWidgets('sin nombre no se manda nada al servidor', (tester) async {
    final servidor = _ServidorFalso();
    await abrirPerfil(tester, servidor);

    await tester.enterText(campo('Nombre'), '   ');
    await tester.tap(find.text('Guardar cambios'));
    await tester.pumpAndSettle();

    expect(find.text('Escribe tu nombre.'), findsOneWidget);
    expect(servidor.pedidas('PATCH', '/perfil'), isEmpty);
  });

  testWidgets('un error de validación del servidor sale debajo del campo y se quita al corregir', (tester) async {
    var intentos = 0;
    final servidor = _ServidorFalso(
      alGuardar: (cuerpo) {
        intentos++;
        if (intentos == 1) {
          return _json({
            'message': 'El teléfono no puede tener más de 30 caracteres.',
            'errors': {
              'telefono': ['El teléfono no puede tener más de 30 caracteres.'],
            },
          }, 422);
        }
        return _json({
          'data': {
            'id': 7,
            'nombre': cuerpo['nombre'],
            'email': 'maria.lopez@revendedor.test',
            'telefono': cuerpo['telefono'],
            'estado': 'activo',
          },
          'message': 'Datos actualizados correctamente.',
        }, 200);
      },
    );
    await abrirPerfil(tester, servidor);

    await tester.tap(find.text('Guardar cambios'));
    await tester.pumpAndSettle();
    expect(find.text('El teléfono no puede tener más de 30 caracteres.'), findsOneWidget);

    // Al corregir el campo, el error se quita y se puede volver a guardar.
    await tester.enterText(campo('Teléfono (opcional)'), '9630001111');
    await tester.pump();
    expect(find.text('El teléfono no puede tener más de 30 caracteres.'), findsNothing);

    await tester.tap(find.text('Guardar cambios'));
    await tester.pumpAndSettle();
    expect(servidor.pedidas('PATCH', '/perfil'), hasLength(2));
    expect(find.text('Datos actualizados correctamente.'), findsOneWidget);
  });

  testWidgets('"Cambiar contraseña" abre su pantalla', (tester) async {
    await abrirPerfil(tester, _ServidorFalso());

    await tester.tap(find.text('Cambiar contraseña'));
    await tester.pumpAndSettle();

    expect(find.text('Contraseña actual'), findsOneWidget);
    expect(find.text('Nueva contraseña'), findsOneWidget);
  });
}
