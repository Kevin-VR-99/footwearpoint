import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:footwearpoint/main.dart';
import 'package:footwearpoint/screens/perfil_screen.dart';
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
  _ServidorFalso({this.alGuardar, this.distribuidora = 'Calzados Ramírez', this.rol = 'revendedor'});

  final String rol;

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
          'rol': rol,
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

    if (ruta.endsWith('/perfil/foto') && peticion.method == 'POST') {
      usuario = {...usuario, 'foto_url': 'https://fotos.test/maria.jpg'};
      return _json({'data': usuario, 'message': 'Foto actualizada.'}, 200);
    }

    if (ruta.endsWith('/perfil/correo') && peticion.method == 'POST') {
      final cuerpo = jsonDecode(peticion.body) as Map<String, dynamic>;
      if (cuerpo['email'] == 'malo') {
        return _json({
          'message': 'Escribe un correo válido.',
          'errors': {'email': ['Escribe un correo válido.']},
        }, 422);
      }
      usuario = {...usuario, 'cambios_pendientes': {'email': cuerpo['email']}};
      return _json({'data': usuario, 'message': 'Te mandamos un enlace.'}, 200);
    }

    if (ruta.endsWith('/perfil/telefono') && peticion.method == 'POST') {
      final cuerpo = jsonDecode(peticion.body) as Map<String, dynamic>;
      usuario = {...usuario, 'cambios_pendientes': {'telefono': cuerpo['telefono']}};
      return _json({'data': usuario, 'message': 'Te mandamos un enlace.'}, 200);
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

  testWidgets('al cliente directo el correo se le ve pero no se puede editar', (tester) async {
    await abrirPerfil(tester, _ServidorFalso(rol: 'cliente_directo'));

    final correo = tester.widget<TextField>(find.widgetWithText(TextField, 'Correo'));
    expect(correo.enabled, isFalse);
    expect(find.text('El correo no se puede cambiar.'), findsOneWidget);
  });

  testWidgets('el cliente directo sigue guardando nombre y teléfono juntos', (tester) async {
    final servidor = _ServidorFalso(rol: 'cliente_directo');
    await abrirPerfil(tester, servidor);

    await tester.enterText(campo('Teléfono (opcional)'), '9630001111');
    await tester.ensureVisible(find.text('Guardar cambios'));
    await tester.tap(find.text('Guardar cambios'));
    await tester.pumpAndSettle();

    final patch = servidor.pedidas('PATCH', '/perfil').single;
    expect(jsonDecode(patch.body), {'nombre': 'María López', 'telefono': '9630001111'});
    expect(find.text('Cambiar correo'), findsNothing);
  });

  testWidgets('el revendedor guarda solo el nombre; el teléfono no se edita directo', (tester) async {
    final servidor = _ServidorFalso();
    await abrirPerfil(tester, servidor);

    // Se cambia tambien el nombre de pila, para poder ver si el cambio
    // llego al resto de la app y no solo a este formulario.
    await tester.enterText(campo('Nombre'), '  Rosa Pérez ');
    final telefono = tester.widget<TextField>(find.widgetWithText(TextField, 'Teléfono'));
    expect(telefono.enabled, isFalse);
    await tester.ensureVisible(find.text('Guardar cambios'));
    await tester.tap(find.text('Guardar cambios'));
    await tester.pumpAndSettle();

    final patch = servidor.pedidas('PATCH', '/perfil').single;
    expect(jsonDecode(patch.body), {'nombre': 'Rosa Pérez'});
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
            'message': 'Ese nombre no se puede usar.',
            'errors': {
              'nombre': ['Ese nombre no se puede usar.'],
            },
          }, 422);
        }
        return _json({
          'data': {
            'id': 7,
            'nombre': cuerpo['nombre'],
            'email': 'maria.lopez@revendedor.test',
            'telefono': '9635551111',
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
    expect(find.text('Ese nombre no se puede usar.'), findsOneWidget);

    // Al corregir el campo, el error se quita y se puede volver a guardar.
    await tester.enterText(campo('Nombre'), 'María L.');
    await tester.pump();
    expect(find.text('Ese nombre no se puede usar.'), findsNothing);

    await tester.ensureVisible(find.text('Guardar cambios'));
    await tester.pumpAndSettle();
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

  group('revendedor: foto, correo y teléfono', () {
    testWidgets('cambiar foto la sube como multipart', (tester) async {
      final original = PerfilScreen.elegirFoto;
      addTearDown(() => PerfilScreen.elegirFoto = original);
      PerfilScreen.elegirFoto = () async => const FotoElegida(bytes: [1, 2, 3], nombre: 'yo.jpg');

      final servidor = _ServidorFalso();
      await abrirPerfil(tester, servidor);
      await tester.tap(find.text('Cambiar foto'));
      await tester.pumpAndSettle();

      final subida = servidor.pedidas('POST', '/perfil/foto').single;
      expect(subida.headers['content-type'], startsWith('multipart/form-data'));
      expect(utf8.decode(subida.bodyBytes, allowMalformed: true), contains('name="foto"; filename="yo.jpg"'));
      expect(find.text('Foto actualizada.'), findsOneWidget);
    });

    testWidgets('si no elige foto no se manda nada', (tester) async {
      final original = PerfilScreen.elegirFoto;
      addTearDown(() => PerfilScreen.elegirFoto = original);
      PerfilScreen.elegirFoto = () async => null;

      final servidor = _ServidorFalso();
      await abrirPerfil(tester, servidor);
      await tester.tap(find.text('Cambiar foto'));
      await tester.pumpAndSettle();

      expect(servidor.pedidas('POST', '/perfil/foto'), isEmpty);
    });

    testWidgets('cambiar correo manda el enlace y lo enseña como pendiente', (tester) async {
      final servidor = _ServidorFalso();
      await abrirPerfil(tester, servidor);

      expect(find.text('Para cambiarlo te mandamos un enlace de confirmación.'), findsNWidgets(2));
      await tester.ensureVisible(find.text('Cambiar correo'));
      await tester.tap(find.text('Cambiar correo'));
      await tester.pumpAndSettle();

      await tester.enterText(find.widgetWithText(TextField, 'Correo nuevo'), 'malo');
      await tester.tap(find.text('Mandar enlace'));
      await tester.pumpAndSettle();
      expect(find.text('Escribe un correo válido.'), findsOneWidget);

      await tester.enterText(find.widgetWithText(TextField, 'Correo nuevo'), 'maria.nueva@gmail.com');
      await tester.tap(find.text('Mandar enlace'));
      await tester.pumpAndSettle();

      expect(jsonDecode(servidor.pedidas('POST', '/perfil/correo').last.body), {'email': 'maria.nueva@gmail.com'});
      expect(find.text('Pendiente de confirmar'), findsOneWidget);
      expect(find.text('Correo nuevo: maria.nueva@gmail.com'), findsOneWidget);
      // El correo actual no cambia hasta que se confirme.
      expect(find.text('maria.lopez@revendedor.test'), findsOneWidget);
    });

    testWidgets('cambiar teléfono pide confirmación por correo', (tester) async {
      final servidor = _ServidorFalso();
      await abrirPerfil(tester, servidor);

      await tester.ensureVisible(find.text('Cambiar teléfono'));
      await tester.tap(find.text('Cambiar teléfono'));
      await tester.pumpAndSettle();
      await tester.enterText(find.widgetWithText(TextField, 'Teléfono nuevo'), '9611234567');
      await tester.tap(find.text('Mandar enlace'));
      await tester.pumpAndSettle();

      expect(jsonDecode(servidor.pedidas('POST', '/perfil/telefono').single.body), {'telefono': '9611234567'});
      expect(find.text('Teléfono nuevo: 9611234567'), findsOneWidget);
      expect(find.text('9635551111'), findsOneWidget);
    });

    testWidgets('cancelar el diálogo no manda nada', (tester) async {
      final servidor = _ServidorFalso();
      await abrirPerfil(tester, servidor);

      await tester.ensureVisible(find.text('Cambiar correo'));
      await tester.tap(find.text('Cambiar correo'));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Cancelar'));
      await tester.pumpAndSettle();

      expect(servidor.pedidas('POST', '/perfil/correo'), isEmpty);
    });
  });
}
