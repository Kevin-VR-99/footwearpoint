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

/// Un servidor falso con sesión de María, 2 notificaciones sin leer (una de
/// pedido) y 1 leída. Recuerda cuáles se marcaron.
class _Servidor {
  _Servidor({this.borrarFalla = false});

  final marcadas = <int>{};

  /// TG-281: los que se quitaron de la bandeja.
  final borradas = <int>{};

  /// Para probar que, si el servidor dice que no, el aviso vuelve.
  final bool borrarFalla;

  final peticiones = <String>[];

  List<Map<String, dynamic>> get _notificaciones => [
    for (final n in _todas)
      if (!borradas.contains(n['id'])) n,
  ];

  List<Map<String, dynamic>> get _todas => [
    {
      'id': 30,
      'tipo': 'pedido_estado',
      'titulo': 'Tu pedido ya llegó',
      'mensaje': 'El pedido PED-015 ya está en la distribuidora.',
      'leida': marcadas.contains(30),
      'entidad_tipo': 'pedido',
      'entidad_id': 15,
      'created_at': '2026-09-18T12:00:00-06:00',
    },
    {
      'id': 31,
      'tipo': 'aviso',
      'titulo': 'Aviso general',
      'mensaje': 'Horario especial el lunes.',
      'leida': marcadas.contains(31),
      'entidad_tipo': null,
      'entidad_id': null,
      'created_at': '2026-09-17T09:00:00-06:00',
    },
    {
      'id': 29,
      'tipo': 'pedido_estado',
      'titulo': 'Pedido en tránsito',
      'mensaje': 'Tu pedido viene en camino.',
      'leida': true,
      'entidad_tipo': 'pedido',
      'entidad_id': 14,
      'created_at': '2026-09-16T09:00:00-06:00',
    },
  ];

  MockClient get cliente => MockClient((peticion) async {
    final ruta = peticion.url.path.replaceFirst('/api', '');
    peticiones.add('${peticion.method} $ruta');

    if (ruta == '/auth/me') {
      return _json({
        'data': {
          'usuario': {'id': 7, 'nombre': 'María López', 'email': 'maria@ejemplo.com', 'telefono': null, 'estado': 'activo'},
          'rol': 'revendedor',
          'legales_aceptados': true,
          'distribuidora_id': 1,
        },
      }, 200);
    }
    if (ruta == '/notificaciones') {
      final soloNoLeidas = peticion.url.queryParameters['solo_no_leidas'] != null;
      return _json({
        'data': [
          for (final n in _notificaciones)
            if (!soloNoLeidas || n['leida'] == false) n,
        ],
      }, 200);
    }
    final borrar = RegExp(r'^/notificaciones/(\d+)$').firstMatch(ruta);
    if (borrar != null && peticion.method == 'DELETE') {
      if (borrarFalla) {
        return _json({'message': 'No se encontró lo que buscas.'}, 404);
      }
      borradas.add(int.parse(borrar.group(1)!));
      return _json({'message': 'Aviso borrado.'}, 200);
    }
    final marcar = RegExp(r'^/notificaciones/(\d+)/marcar-leida$').firstMatch(ruta);
    if (marcar != null) {
      marcadas.add(int.parse(marcar.group(1)!));
      return _json({'data': {}, 'message': 'Notificación marcada como leída.'}, 200);
    }
    if (ruta == '/pedidos/15') {
      return _json({
        'data': {
          'id': 15,
          'folio': 'PED-015',
          'tipo': 'revendedor',
          'estado': 'recibido_distribuidora',
          'total': 1000.0,
          'pagado': 0.0,
          'pagado_con_vales': 0.0,
          'saldo': 1000.0,
          'anticipo_requerido': 0.0,
          'anticipo_pagado': 0.0,
          'anticipo_pendiente': 0.0,
          'lineas': <Object>[],
          'pagos': <Object>[],
        },
      }, 200);
    }
    return _json({'message': 'No encontrado.'}, 404);
  });
}

/// Deja pasar el tiempo hasta que el aviso de abajo se cierra solo.
///
/// pumpAndSettle no adelanta temporizadores, y el aviso se va por tiempo
/// (4 s), no por una animación. Se avanza el reloj de a poco porque el
/// temporizador no existe hasta que termina de entrar.
Future<void> esperarAQueSeCierreElAviso(WidgetTester tester) async {
  for (var i = 0; i < 8; i++) {
    await tester.pump(const Duration(seconds: 1));
  }
  await tester.pumpAndSettle();
}

void main() {
  setUp(() => FlutterSecureStorage.setMockInitialValues(<String, String>{'token_sanctum': '1|token'}));

  Future<_Servidor> abrirBandeja(WidgetTester tester, [_Servidor? propio]) async {
    final servidor = propio ?? _Servidor();

    await tester.pumpWidget(FootwearPointApp(api: ApiService(cliente: servidor.cliente)));
    await tester.pumpAndSettle();

    await tester.tap(find.byTooltip('Notificaciones (2 sin leer)'));
    await tester.pumpAndSettle();

    return servidor;
  }

  testWidgets('la campana del inicio muestra cuántas hay sin leer', (tester) async {
    final servidor = _Servidor();

    await tester.pumpWidget(FootwearPointApp(api: ApiService(cliente: servidor.cliente)));
    await tester.pumpAndSettle();

    expect(find.byTooltip('Notificaciones (2 sin leer)'), findsOneWidget);
    expect(find.text('2'), findsOneWidget);
  });

  testWidgets('las no leídas se distinguen y las de pedido ofrecen verlo', (tester) async {
    await abrirBandeja(tester);

    expect(find.text('Tu pedido ya llegó'), findsOneWidget);
    expect(find.byTooltip('Sin leer'), findsNWidgets(2));
    expect(find.text('Ver pedido'), findsNWidgets(2));
    expect(find.text('18/09/2026 12:00'), findsOneWidget);
  });

  testWidgets('tocar una no leída de pedido la marca y abre el pedido', (tester) async {
    final servidor = await abrirBandeja(tester);

    await tester.tap(find.text('Tu pedido ya llegó'));
    await tester.pumpAndSettle();

    expect(servidor.peticiones, contains('POST /notificaciones/30/marcar-leida'));
    expect(servidor.peticiones, contains('GET /pedidos/15'));
    expect(find.text('Tu pedido ya llegó a la distribuidora.'), findsOneWidget);

    // Al regresar a la bandeja ya se ve como leída.
    await tester.pageBack();
    await tester.pumpAndSettle();
    expect(find.byTooltip('Sin leer'), findsOneWidget);
  });

  testWidgets('tocar una ya leída no vuelve a marcarla', (tester) async {
    final servidor = await abrirBandeja(tester);

    await tester.tap(find.text('Pedido en tránsito'));
    await tester.pumpAndSettle();

    expect(servidor.peticiones.where((p) => p.contains('marcar-leida')), isEmpty);
    expect(servidor.peticiones, contains('GET /pedidos/14'));
  });

  testWidgets('al regresar de la bandeja, la campana se actualiza', (tester) async {
    await abrirBandeja(tester);

    // Se lee el aviso general (no es de pedido: no abre nada).
    await tester.tap(find.text('Aviso general'));
    await tester.pumpAndSettle();

    await tester.pageBack();
    await tester.pumpAndSettle();

    expect(find.byTooltip('Notificaciones (1 sin leer)'), findsOneWidget);
  });

  /// TG-281 (E19-11), punto 4: quitar un aviso deslizándolo.
  ///
  /// La bandeja se llenaba y no había forma de limpiarla.
  testWidgets('deslizar un aviso hacia la derecha lo quita de la bandeja', (tester) async {
    final servidor = await abrirBandeja(tester);

    expect(find.text('Aviso general'), findsOneWidget);

    await tester.drag(find.text('Aviso general'), const Offset(700, 0));
    await tester.pumpAndSettle();

    // Se quita de la pantalla enseguida, pero al servidor no se le pide nada
    // hasta que el aviso de abajo se cierra solo.
    expect(find.text('Aviso general'), findsNothing);
    expect(servidor.peticiones.where((p) => p.startsWith('DELETE')), isEmpty);

    // pumpAndSettle no adelanta temporizadores: hay que mover el reloj a
    // mano para que el aviso de abajo se cierre solo.
    await esperarAQueSeCierreElAviso(tester);

    expect(servidor.peticiones, contains('DELETE /notificaciones/31'));
    expect(find.text('Aviso general'), findsNothing);
    // Los demás se quedan.
    expect(find.text('Tu pedido ya llegó'), findsOneWidget);
  });

  testWidgets('al borrar se avisa y se ofrece deshacer', (tester) async {
    await abrirBandeja(tester);

    await tester.drag(find.text('Aviso general'), const Offset(700, 0));
    await tester.pumpAndSettle();

    expect(find.text('Aviso borrado.'), findsOneWidget);
    expect(find.text('Deshacer'), findsOneWidget);

    // Se deja pasar el margen para deshacer, o la prueba termina con un
    // temporizador pendiente.
    await esperarAQueSeCierreElAviso(tester);
  });

  testWidgets('si el servidor no deja borrarlo, el aviso vuelve a su sitio', (tester) async {
    await abrirBandeja(tester, _Servidor(borrarFalla: true));

    await tester.drag(find.text('Aviso general'), const Offset(700, 0));
    await tester.pumpAndSettle();

    // Justo despues del margen para deshacer, que es cuando se manda el
    // borrado y el servidor lo rechaza.
    await tester.pump(const Duration(seconds: 5));
    // Deja que el aviso viejo se vaya y entre el del error.
    await tester.pumpAndSettle();

    // Es peor que desaparezca de la pantalla algo que sigue en el servidor.
    expect(find.text('Aviso general'), findsOneWidget);
    expect(find.text('No se encontró lo que buscas.'), findsOneWidget);

    await esperarAQueSeCierreElAviso(tester);
  });

  testWidgets('tocar "Deshacer" devuelve el aviso y no le pide nada al servidor', (tester) async {
    final servidor = await abrirBandeja(tester);

    await tester.drag(find.text('Aviso general'), const Offset(700, 0));
    await tester.pumpAndSettle();

    await tester.tap(find.text('Deshacer'));
    await tester.pumpAndSettle();

    // Vuelve en el acto, sin esperar a que termine el margen: si no, se
    // tocaria "Deshacer" y no pasaria nada durante unos segundos.
    expect(find.text('Aviso general'), findsOneWidget);

    await esperarAQueSeCierreElAviso(tester);
    expect(find.text('Aviso general'), findsOneWidget);
    // En el servidor no hay forma de des-borrar, asi que lo importante es
    // que nunca se le haya pedido.
    expect(servidor.peticiones.where((p) => p.startsWith('DELETE')), isEmpty);
  });

  testWidgets('deslizar hacia la izquierda no borra nada', (tester) async {
    final servidor = await abrirBandeja(tester);

    await tester.drag(find.text('Aviso general'), const Offset(-700, 0));
    await tester.pumpAndSettle();

    // A proposito: con las dos direcciones es facil borrar sin querer al
    // desplazarse en diagonal.
    expect(find.text('Aviso general'), findsOneWidget);
    expect(servidor.peticiones.where((p) => p.startsWith('DELETE')), isEmpty);
  });
}
