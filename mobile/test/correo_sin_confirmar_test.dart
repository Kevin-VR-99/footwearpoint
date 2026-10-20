import 'dart:convert';

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

/// TG-189 (A11) en la app: el aviso de correo sin confirmar y el botón para
/// pedir otro enlace.
///
/// Sin esto, el servidor le decía a la persona "pide otro desde tu perfil" y
/// en el perfil no había nada: el mensaje la mandaba a un lugar que no
/// existía.
class _ServidorFalso {
  _ServidorFalso({required this.verificado, this.reenvioFalla = false});

  /// Se puede cambiar en media prueba: es lo que pasa de verdad cuando la
  /// persona abre el enlace en su correo y regresa a la app.
  bool verificado;
  final bool reenvioFalla;
  final peticiones = <http.Request>[];

  final usuario = const {
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
          'legales_aceptados': true,
          'email_verificado': verificado,
        },
      }, 200);
    }

    if (ruta.endsWith('/perfil') && peticion.method == 'GET') {
      return _json({'data': usuario}, 200);
    }

    if (ruta.endsWith('/auth/reenviar-verificacion')) {
      if (reenvioFalla) {
        return _json({'message': 'Espera un momento antes de pedir otro enlace.'}, 429);
      }

      return _json({
        'message': 'Si tu correo está sin confirmar, te mandamos un enlace nuevo. '
            'Revisa tu bandeja y la carpeta de spam.',
      }, 200);
    }

    return _json({'message': 'Not Found'}, 404);
  });

  List<http.Request> pedidas(String rutaTermina) =>
      peticiones.where((p) => p.url.path.endsWith(rutaTermina)).toList();
}

void main() {
  setUp(() {
    FlutterSecureStorage.setMockInitialValues(<String, String>{
      'token_sanctum': '1|token-de-prueba',
    });
  });

  Future<void> abrirPerfil(WidgetTester tester, _ServidorFalso servidor) async {
    await tester.pumpWidget(FootwearPointApp(api: ApiService(cliente: servidor.cliente)));
    await tester.pumpAndSettle();

    // TG-281: ya no hay tarjeta "Mi perfil" en el inicio; se entra por el
    // avatar de la barra de arriba.
    await tester.tap(find.byTooltip('Mi perfil'));
    await tester.pumpAndSettle();
  }

  testWidgets('con el correo sin confirmar, el perfil lo avisa y ofrece el botón', (tester) async {
    await abrirPerfil(tester, _ServidorFalso(verificado: false));

    expect(find.text('Falta confirmar tu correo'), findsOneWidget);
    expect(find.text('Mandarme otro enlace'), findsOneWidget);
  });

  testWidgets('con el correo confirmado no se le dice nada', (tester) async {
    await abrirPerfil(tester, _ServidorFalso(verificado: true));

    expect(find.text('Falta confirmar tu correo'), findsNothing);
    expect(find.text('Mandarme otro enlace'), findsNothing);
  });

  testWidgets('el botón pide otro enlace y enseña lo que respondió el servidor', (tester) async {
    final servidor = _ServidorFalso(verificado: false);
    await abrirPerfil(tester, servidor);

    await tester.ensureVisible(find.text('Mandarme otro enlace'));
    await tester.tap(find.text('Mandarme otro enlace'));
    await tester.pumpAndSettle();

    expect(servidor.pedidas('/auth/reenviar-verificacion'), hasLength(1));
    expect(find.textContaining('te mandamos un enlace nuevo'), findsOneWidget);
  });

  testWidgets('si ya confirmó mientras tanto, al volver al perfil el aviso se quita', (tester) async {
    final servidor = _ServidorFalso(verificado: false);
    await abrirPerfil(tester, servidor);

    expect(find.text('Falta confirmar tu correo'), findsOneWidget);

    // Abre el enlace en su correo (fuera de la app) y regresa al perfil.
    servidor.verificado = true;
    await tester.pageBack();
    await tester.pumpAndSettle();
    // TG-281: ya no hay tarjeta "Mi perfil" en el inicio; se entra por el
    // avatar de la barra de arriba.
    await tester.tap(find.byTooltip('Mi perfil'));
    await tester.pumpAndSettle();

    expect(find.text('Falta confirmar tu correo'), findsNothing);
  });

  testWidgets('si el servidor dice que espere, se lo dice sin tecnicismos', (tester) async {
    final servidor = _ServidorFalso(verificado: false, reenvioFalla: true);
    await abrirPerfil(tester, servidor);

    await tester.ensureVisible(find.text('Mandarme otro enlace'));
    await tester.tap(find.text('Mandarme otro enlace'));
    await tester.pumpAndSettle();

    expect(find.text('Espera un momento antes de pedir otro enlace.'), findsOneWidget);
  });
}
