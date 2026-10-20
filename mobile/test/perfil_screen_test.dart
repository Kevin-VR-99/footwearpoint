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
  _ServidorFalso({this.alGuardar, this.distribuidora = 'Calzados Ramírez'});

  final http.Response Function(Map<String, dynamic> cuerpo)? alGuardar;
  final String? distribuidora;
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
        'data': {
          'usuario': usuario,
          'rol': 'revendedor',
          'distribuidora_id': 1,
          'distribuidora': distribuidora,
          'legales_aceptados': true,
        },
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

  /// Abre la app con sesión y entra a "Mi perfil" desde el avatar del inicio.
  Future<void> abrirPerfil(WidgetTester tester, _ServidorFalso servidor) async {
    await tester.pumpWidget(FootwearPointApp(api: ApiService(cliente: servidor.cliente)));
    await tester.pumpAndSettle();

    // En pantallas chicas el inicio se desliza: primero se lleva el botón a la vista.
    // TG-281: ya no hay tarjeta "Mi perfil" en el inicio; se entra por el
    // avatar de la barra de arriba.
    await tester.tap(find.byTooltip('Mi perfil'));
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
    // TG-281: ya no hay tarjeta "Mi perfil" en el inicio; se entra por el
    // avatar de la barra de arriba.
    await tester.tap(find.byTooltip('Mi perfil'));
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

    // Se cambia tambien el nombre de pila, para poder ver si el cambio
    // llego al resto de la app y no solo a este formulario.
    await tester.enterText(campo('Nombre'), '  Rosa Pérez ');
    await tester.enterText(campo('Teléfono (opcional)'), '9630001111');
    await tester.ensureVisible(find.text('Guardar cambios'));
    await tester.tap(find.text('Guardar cambios'));
    await tester.pumpAndSettle();

    final patch = servidor.pedidas('PATCH', '/perfil').single;
    expect(jsonDecode(patch.body), {'nombre': 'Rosa Pérez', 'telefono': '9630001111'});
    expect(find.text('Datos actualizados correctamente.'), findsOneWidget);

    // TG-281: el inicio ya no lista los datos de la cuenta, pero sigue
    // saludando por el nombre. Si el saludo cambia, el nombre nuevo llego a
    // la sesion y no se quedo solo en el formulario.
    await tester.pageBack();
    await tester.pumpAndSettle();
    expect(find.text('Hola, Rosa'), findsOneWidget);
  });

  testWidgets('sin nombre no se manda nada al servidor', (tester) async {
    final servidor = _ServidorFalso();
    await abrirPerfil(tester, servidor);

    await tester.enterText(campo('Nombre'), '   ');
    await tester.ensureVisible(find.text('Guardar cambios'));
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

    await tester.ensureVisible(find.text('Guardar cambios'));
    await tester.tap(find.text('Guardar cambios'));
    await tester.pumpAndSettle();
    expect(find.text('El teléfono no puede tener más de 30 caracteres.'), findsOneWidget);

    // Al corregir el campo, el error se quita y se puede volver a guardar.
    await tester.enterText(campo('Teléfono (opcional)'), '9630001111');
    await tester.pump();
    expect(find.text('El teléfono no puede tener más de 30 caracteres.'), findsNothing);

    await tester.ensureVisible(find.text('Guardar cambios'));
    await tester.tap(find.text('Guardar cambios'));
    await tester.pumpAndSettle();
    expect(servidor.pedidas('PATCH', '/perfil'), hasLength(2));
    expect(find.text('Datos actualizados correctamente.'), findsOneWidget);
  });

  /// TG-281: esto vivia en el Inicio. Se movio aqui, que es donde la gente
  /// va a buscar sus datos, y el Inicio quedo solo con los accesos.
  testWidgets('el perfil muestra el rol y la distribuidora por su nombre', (tester) async {
    await abrirPerfil(tester, _ServidorFalso());

    await tester.ensureVisible(find.text('Tu cuenta'));

    expect(find.text('Rol'), findsOneWidget);
    expect(find.text('Cliente mayorista'), findsOneWidget);
    expect(find.text('Distribuidora'), findsOneWidget);
    // Antes decia "1", que no le dice nada a nadie.
    expect(find.text('Calzados Ramírez'), findsOneWidget);
    expect(find.text('1'), findsNothing);
  });

  testWidgets('"Cambiar contraseña" abre su pantalla', (tester) async {
    await abrirPerfil(tester, _ServidorFalso());

    await tester.ensureVisible(find.text('Cambiar contraseña'));
    await tester.tap(find.text('Cambiar contraseña'));
    await tester.pumpAndSettle();

    expect(find.text('Contraseña actual'), findsOneWidget);
    expect(find.text('Nueva contraseña'), findsOneWidget);
  });

  testWidgets('enseña el nombre real de la distribuidora, no su número', (tester) async {
    await abrirPerfil(tester, _ServidorFalso());
    await tester.ensureVisible(find.text('Calzados Ramírez'));

    expect(find.text('Calzados Ramírez'), findsOneWidget);
    expect(find.text('1'), findsNothing);
  });

  testWidgets('si el servidor no manda el nombre, no se cae a «1»', (tester) async {
    await abrirPerfil(tester, _ServidorFalso(distribuidora: null));
    await tester.ensureVisible(find.text('Distribuidora'));

    expect(find.text('1'), findsNothing);
    expect(find.textContaining('Distribuidora 1'), findsNothing);
  });
}
