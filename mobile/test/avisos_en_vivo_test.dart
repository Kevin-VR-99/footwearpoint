import 'dart:convert';

import 'package:firebase_messaging/firebase_messaging.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:footwearpoint/main.dart';
import 'package:footwearpoint/services/api_service.dart';
import 'package:footwearpoint/services/avisos_en_vivo.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

http.Response _json(Object cuerpo, int codigo) => http.Response(
  jsonEncode(cuerpo),
  codigo,
  headers: {'content-type': 'application/json; charset=utf-8'},
);

/// TG-281: los avisos que llegan con la app ABIERTA.
///
/// Reportado al probar: estando dentro de la app no salía nada, y en la
/// campanita el aviso solo aparecía al tocarla; el globito rojo no se ponía
/// solo. La causa era que nadie escuchaba `FirebaseMessaging.onMessage`:
/// Android no enseña esos avisos por su cuenta, se los entrega a la app.
Map<String, dynamic> _aviso(String titulo, String mensaje, {bool leida = false}) => {
  'id': titulo.hashCode.abs() % 1000,
  'tipo': 'vale_emitido',
  'titulo': titulo,
  'mensaje': mensaje,
  'leida': leida,
  'entidad_tipo': 'vale',
  'entidad_id': 5,
  'created_at': '2026-10-22T10:00:00-06:00',
};

/// Un servidor falso cuya bandeja crece cuando la prueba lo dice.
class _Servidor {
  final avisos = <Map<String, dynamic>>[];

  MockClient get cliente => MockClient((peticion) async {
    final ruta = peticion.url.path.replaceFirst('/api', '');

    if (ruta == '/auth/me') {
      return _json({
        'data': {
          'usuario': {
            'id': 7,
            'nombre': 'María López',
            'email': 'maria@ejemplo.com',
            'telefono': null,
            'estado': 'activo',
          },
          'rol': 'revendedor',
          'legales_aceptados': true,
          'distribuidora_id': 1,
          'distribuidora': 'Calzados Ramírez',
        },
      }, 200);
    }

    if (ruta == '/notificaciones') {
      final soloNoLeidas = peticion.url.queryParameters['solo_no_leidas'] != null;

      return _json({
        'data': [
          for (final a in avisos)
            if (!soloNoLeidas || a['leida'] == false) a,
        ],
      }, 200);
    }

    return _json({'data': <Object>[]}, 200);
  });
}

void main() {
  setUp(() {
    FlutterSecureStorage.setMockInitialValues(<String, String>{'token_sanctum': '1|token'});
  });

  Future<_Servidor> abrir(WidgetTester tester) async {
    final servidor = _Servidor();

    await tester.pumpWidget(FootwearPointApp(api: ApiService(cliente: servidor.cliente)));
    await tester.pumpAndSettle();

    return servidor;
  }

  testWidgets('al llegar un aviso con la app abierta, el globito rojo aparece solo', (tester) async {
    final servidor = await abrir(tester);

    // Al entrar no hay nada sin leer.
    expect(find.byTooltip('Notificaciones'), findsOneWidget);

    // Llega uno: el servidor ya lo tiene y Firebase lo entrega.
    servidor.avisos.add(_aviso('Te emitieron un vale', 'Tienes un vale de \$750.00.'));
    AvisosEnVivo.simular(const RemoteMessage(data: {'tipo': 'vale_emitido'}));
    await tester.pumpAndSettle();

    // Lo que fallaba: había que tocar la campanita para enterarse.
    expect(find.byTooltip('Notificaciones (1 sin leer)'), findsOneWidget);
    expect(find.text('1'), findsOneWidget);
  });

  testWidgets('y se enseña una franja dentro de la app, con lo que dice el aviso', (tester) async {
    await abrir(tester);

    AvisosEnVivo.simular(
      const RemoteMessage(
        notification: RemoteNotification(
          title: 'Te emitieron un vale',
          body: 'Tienes un vale de 750 pesos para usar en tus compras.',
        ),
        data: {'tipo': 'vale_emitido'},
      ),
    );
    await tester.pumpAndSettle();

    expect(find.text('Te emitieron un vale'), findsOneWidget);
    expect(find.textContaining('750 pesos'), findsOneWidget);
    expect(find.text('Ver'), findsOneWidget);
  });

  testWidgets('tocar «Ver» lleva a la bandeja', (tester) async {
    final servidor = await abrir(tester);
    servidor.avisos.add(_aviso('Te emitieron un vale', 'Tienes un vale.'));

    AvisosEnVivo.simular(
      const RemoteMessage(
        notification: RemoteNotification(title: 'Te emitieron un vale', body: 'Tienes un vale.'),
        data: {'tipo': 'vale_emitido'},
      ),
    );
    await tester.pumpAndSettle();

    await tester.tap(find.text('Ver'));
    await tester.pumpAndSettle();

    expect(find.text('Notificaciones'), findsOneWidget);
  });

  testWidgets('si el aviso viene sin título, igual se avisa de que llegó algo', (tester) async {
    await abrir(tester);

    AvisosEnVivo.simular(const RemoteMessage(data: {'tipo': 'vale_emitido'}));
    await tester.pumpAndSettle();

    expect(find.text('Tienes un aviso nuevo'), findsOneWidget);
  });
}
