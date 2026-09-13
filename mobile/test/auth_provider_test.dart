import 'dart:convert';
import 'dart:io';

import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:footwearpoint/providers/auth_provider.dart';
import 'package:footwearpoint/services/api_service.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Lo que regresan login y auth/me: la forma de docs/contrato-api.md
/// (sección 2). auth/me es igual, pero sin token.
const _sesion = {
  'usuario': {
    'id': 7,
    'nombre': 'María López',
    'email': 'maria@ejemplo.com',
    'telefono': '9631234567',
    'estado': 'activo',
  },
  'rol': 'revendedor',
  'distribuidora_id': 1,
};

http.Response _json(Object cuerpo, int codigo) => http.Response(
  jsonEncode(cuerpo),
  codigo,
  headers: {'content-type': 'application/json; charset=utf-8'},
);

http.Response _me200() => _json({'data': _sesion}, 200);
http.Response _no401() => _json({'message': 'Unauthenticated.'}, 401);

void main() {
  late Map<String, String> almacen;
  late List<String> rutasPedidas;

  setUp(() {
    // El mapa se guarda aparte para poder revisar qué quedó en el "teléfono".
    almacen = <String, String>{};
    FlutterSecureStorage.setMockInitialValues(almacen);
    rutasPedidas = [];
  });

  /// Un servidor falso. El login siempre sale bien; auth/me y todo lo demás
  /// responden lo que diga cada prueba.
  AuthProvider crearProvider({
    http.Response Function()? me,
    http.Response Function()? otra,
  }) {
    final cliente = MockClient((peticion) async {
      final ruta = peticion.url.path;
      rutasPedidas.add(ruta);

      if (ruta.endsWith('/auth/login')) {
        return _json({
          'data': {'token': '12|token-de-prueba', 'token_type': 'Bearer', ..._sesion},
          'message': 'Inicio de sesión exitoso.',
        }, 200);
      }
      if (ruta.endsWith('/auth/me')) return (me ?? _me200)();

      return otra != null ? otra() : _json({'message': 'ok'}, 200);
    });

    return AuthProvider(api: ApiService(cliente: cliente));
  }

  group('al abrir la app', () {
    test('sin token guardado, va al login sin preguntarle al servidor', () async {
      final auth = crearProvider();
      expect(auth.iniciando, isTrue);

      await auth.restaurarSesion();

      expect(auth.iniciando, isFalse);
      expect(auth.haySesion, isFalse);
      expect(rutasPedidas, isEmpty);
    });

    test('con token, pide auth/me y con 200 entra con los datos del servidor', () async {
      almacen['token_sanctum'] = '12|token-de-prueba';

      final auth = crearProvider();
      await auth.restaurarSesion();

      expect(rutasPedidas.single, endsWith('/auth/me'));
      expect(auth.haySesion, isTrue);
      expect(auth.usuario!.nombre, 'María López');
      expect(auth.rol, 'revendedor');
      expect(auth.distribuidoraId, 1);
    });

    test('con token, si auth/me responde 401, borra el token y avisa', () async {
      almacen['token_sanctum'] = '12|token-vencido';

      final auth = crearProvider(me: _no401);
      await auth.restaurarSesion();

      expect(auth.haySesion, isFalse);
      expect(auth.sinConexion, isFalse);
      expect(auth.error, AuthProvider.mensajeSesionVencida);
      expect(almacen, isEmpty);
    });

    test('sin conexión, NO borra el token y deja reintentar', () async {
      almacen['token_sanctum'] = '12|token-de-prueba';
      var hayRed = false;

      final auth = crearProvider(
        me: () => hayRed ? _me200() : throw const SocketException('sin red'),
      );
      await auth.restaurarSesion();

      expect(auth.sinConexion, isTrue);
      expect(auth.haySesion, isFalse);
      expect(almacen['token_sanctum'], '12|token-de-prueba');

      hayRed = true;
      await auth.reintentar();

      expect(auth.sinConexion, isFalse);
      expect(auth.haySesion, isTrue);
      expect(auth.error, isNull);
    });

    test('si auth/me responde otro error (404, 500), borra el token y va al login', () async {
      almacen['token_sanctum'] = '12|token-de-prueba';

      final auth = crearProvider(me: () => _json({'message': 'Not Found'}, 404));
      await auth.restaurarSesion();

      expect(auth.haySesion, isFalse);
      expect(auth.sinConexion, isFalse);
      // No es que la sesión expirara: no se le dice eso.
      expect(auth.error, isNull);
      expect(almacen, isEmpty);
    });

    test('si auth/me responde 200 con otra forma, no arma media sesión', () async {
      almacen['token_sanctum'] = '12|token-de-prueba';

      final auth = crearProvider(
        me: () => _json({'data': {'rol': 'revendedor'}}, 200),
      );
      await auth.restaurarSesion();

      expect(auth.haySesion, isFalse);
      expect(auth.rol, isNull);
      expect(almacen, isEmpty);
    });

    test('borra lo que guardaba la versión anterior (usuario y rol en el teléfono)', () async {
      almacen['sesion_usuario'] = jsonEncode(_sesion);

      final auth = crearProvider();
      await auth.restaurarSesion();

      expect(almacen.containsKey('sesion_usuario'), isFalse);
      expect(auth.haySesion, isFalse);
    });
  });

  test('el login guarda en el teléfono SOLO el token', () async {
    final auth = crearProvider();
    await auth.restaurarSesion();

    expect(await auth.login('maria@ejemplo.com', 'secreto123'), isTrue);

    expect(auth.rol, 'revendedor');
    expect(almacen.keys, ['token_sanctum']);
  });

  test('un 401 en cualquier petición borra la sesión y avisa', () async {
    final auth = crearProvider(otra: _no401);
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
    expect(almacen, isEmpty);
  });

  test('cerrar sesión con respuesta 401 no se cicla y borra todo', () async {
    final auth = crearProvider(otra: _no401);
    await auth.restaurarSesion();
    await auth.login('maria@ejemplo.com', 'secreto123');

    await auth.logout();

    // Una sola petición de logout: el 401 no volvió a llamar a logout().
    expect(rutasPedidas.where((r) => r.endsWith('/auth/logout')), hasLength(1));
    expect(auth.haySesion, isFalse);
    // Fue un cierre voluntario: no se le dice "tu sesión expiró".
    expect(auth.error, isNull);
    expect(almacen, isEmpty);
  });
}
