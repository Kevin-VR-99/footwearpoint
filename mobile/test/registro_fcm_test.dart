import 'package:flutter_test/flutter_test.dart';
import 'package:footwearpoint/services/api_service.dart';
import 'package:footwearpoint/services/registro_fcm.dart';

void main() {
  late List<Duration> esperas;

  RegistroFcm crear(Future<void> Function(String) enviar) =>
      RegistroFcm(enviar: enviar, esperar: (d) async => esperas.add(d));

  setUp(() => esperas = []);

  test(
    'sin red reintenta con espera creciente hasta que el servidor lo guarda',
    () async {
      var intentos = 0;
      final registro = crear((token) async {
        intentos++;
        if (intentos < 3) throw ApiException('Sin conexión.');
      });

      expect(await registro.registrar('tok-1'), isTrue);
      expect(intentos, 3);
      expect(esperas, [const Duration(seconds: 2), const Duration(seconds: 4)]);
      expect(registro.ultimoRegistrado, 'tok-1');
    },
  );

  test('un 500 también se reintenta; se rinde tras el máximo', () async {
    var intentos = 0;
    final registro = crear((_) async {
      intentos++;
      throw ApiException('Error', codigoHttp: 500);
    });

    expect(await registro.registrar('tok'), isFalse);
    expect(intentos, 5);
    expect(esperas, hasLength(4));
    expect(esperas.last, const Duration(seconds: 16));
  });

  test('un 422 o 401 no se reintenta', () async {
    var intentos = 0;
    final registro = crear((_) async {
      intentos++;
      throw ApiException('Token inválido', codigoHttp: 422);
    });

    expect(await registro.registrar('tok'), isFalse);
    expect(intentos, 1);
    expect(esperas, isEmpty);
  });

  test('si getToken falla sin red, se vuelve a pedir', () async {
    final enviados = <String>[];
    final registro = crear((t) async => enviados.add(t));
    var pedidas = 0;

    final ok = await registro.registrarDesde(() async {
      pedidas++;
      if (pedidas == 1) throw Exception('SERVICE_NOT_AVAILABLE');
      return 'tok-2';
    });

    expect(ok, isTrue);
    expect(enviados, ['tok-2']);
  });

  test('detener (cerrar sesión) corta los reintentos pendientes', () async {
    var intentos = 0;
    late RegistroFcm registro;
    registro = RegistroFcm(
      enviar: (_) async {
        intentos++;
        throw ApiException('Sin conexión.');
      },
      esperar: (_) async => registro.detener(),
    );

    expect(await registro.registrar('tok'), isFalse);
    expect(intentos, 1);
  });

  test('un token renovado reemplaza al que se estaba reintentando', () async {
    final enviados = <String>[];
    late RegistroFcm registro;
    var primera = true;
    registro = RegistroFcm(
      enviar: (t) async {
        enviados.add(t);
        if (t == 'viejo') throw ApiException('Sin conexión.');
      },
      esperar: (_) async {
        if (primera) {
          primera = false;
          await registro.registrar('nuevo');
        }
      },
    );

    expect(await registro.registrar('viejo'), isFalse);
    expect(enviados, ['viejo', 'nuevo']);
    expect(registro.ultimoRegistrado, 'nuevo');
  });
}
