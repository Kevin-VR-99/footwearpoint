import 'dart:convert';

import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:footwearpoint/providers/auth_provider.dart';
import 'package:footwearpoint/services/api_service.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Respuesta de login con la misma forma que docs/contrato-api.md (sección 2).
final _respuestaLogin = {
  'data': {
    'token': '12|token-de-prueba',
    'token_type': 'Bearer',
    'usuario': {
      'id': 7,
      'nombre': 'María López',
      'email': 'maria@ejemplo.com',
      'telefono': '9631234567',
      'estado': 'activo',
    },
    'rol': 'revendedor',
    'distribuidora_id': 1,
  },
  'message': 'Inicio de sesión exitoso.',
};

http.Response _json(Object cuerpo, int codigo) => http.Response(
  jsonEncode(cuerpo),
  codigo,
  headers: {'content-type': 'application/json; charset=utf-8'},
);

void main() {
  late Map<String, String> almacen;

  setUp(() {
    // El mapa se guarda aparte para poder revisar qué quedó en el "teléfono".
    almacen = <String, String>{};
    FlutterSecureStorage.setMockInitialValues(almacen);
  });

  /// Un servidor falso: responde el login bien y todo lo demás con [otra].
  AuthProvider crearProvider({http.Response Function()? otra}) {
    final cliente = MockClient((peticion) async {
      if (peticion.url.path.endsWith('/auth/login')) {
        return _json(_respuestaLogin, 200);
      }
      return otra != null ? otra() : _json({'message': 'ok'}, 200);
    });

    return AuthProvider(api: ApiService(cliente: cliente));
  }

  test('el login guarda la sesión y al reabrir la app se recupera', () async {
    final primera = crearProvider();
    await primera.restaurarSesion();

    expect(await primera.login('maria@ejemplo.com', 'secreto123'), isTrue);
    expect(almacen['token_sanctum'], '12|token-de-prueba');

    // "Cerrar y volver a abrir la app": un provider nuevo, mismo teléfono.
    final reabierta = crearProvider();
    expect(reabierta.iniciando, isTrue);

    await reabierta.restaurarSesion();

    expect(reabierta.iniciando, isFalse);
    expect(reabierta.haySesion, isTrue);
    expect(reabierta.usuario!.nombre, 'María López');
    expect(reabierta.rol, 'revendedor');
    expect(reabierta.distribuidoraId, 1);
  });

  test('sin nada guardado, arranca sin sesión', () async {
    final auth = crearProvider();
    await auth.restaurarSesion();

    expect(auth.iniciando, isFalse);
    expect(auth.haySesion, isFalse);
  });

  test('un 401 en cualquier petición borra la sesión y avisa', () async {
    final auth = crearProvider(
      otra: () => _json({'message': 'Unauthenticated.'}, 401),
    );
    await auth.restaurarSesion();
    await auth.login('maria@ejemplo.com', 'secreto123');

    await expectLater(
      auth.api.get('catalogo'),
      throwsA(isA<ApiException>().having((e) => e.codigoHttp, 'codigoHttp', 401)),
    );
    // Deja terminar el borrado en el almacén, que no se espera a propósito.
    await Future<void>.delayed(Duration.zero);

    expect(auth.haySesion, isFalse);
    expect(auth.error, AuthProvider.mensajeSesionVencida);
    expect(almacen.containsKey('token_sanctum'), isFalse);
    expect(almacen.containsKey('sesion_usuario'), isFalse);
  });

  test('cerrar sesión borra todo, aunque el servidor responda 401', () async {
    final auth = crearProvider(
      otra: () => _json({'message': 'Unauthenticated.'}, 401),
    );
    await auth.restaurarSesion();
    await auth.login('maria@ejemplo.com', 'secreto123');

    await auth.logout();

    expect(auth.haySesion, isFalse);
    // Fue un cierre voluntario: no se le dice "tu sesión expiró".
    expect(auth.error, isNull);
    expect(almacen, isEmpty);
  });

  test('si solo quedó el token sin datos del usuario, pide login de nuevo', () async {
    almacen['token_sanctum'] = '1|token-viejo-del-bloque-0';

    final auth = crearProvider();
    await auth.restaurarSesion();

    expect(auth.haySesion, isFalse);
    expect(almacen, isEmpty);
  });

  test('si lo guardado está dañado, arranca sin sesión y lo limpia', () async {
    almacen['token_sanctum'] = '1|token';
    almacen['sesion_usuario'] = 'esto no es json';

    final auth = crearProvider();
    await auth.restaurarSesion();

    expect(auth.haySesion, isFalse);
    expect(auth.iniciando, isFalse);
    expect(almacen, isEmpty);
  });
}
