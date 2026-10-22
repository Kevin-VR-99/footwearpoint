import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:footwearpoint/models/solicitud_cambio.dart';
import 'package:footwearpoint/providers/auth_provider.dart';
import 'package:footwearpoint/screens/historial_cambio_screen.dart';
import 'package:footwearpoint/screens/mis_cambios_screen.dart';
import 'package:footwearpoint/services/aperturas_push.dart';
import 'package:footwearpoint/services/api_service.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:provider/provider.dart';

/// Historial (línea de tiempo) de una solicitud de cambio.

http.Response _json(Object cuerpo, int codigo) => http.Response(
      jsonEncode(cuerpo),
      codigo,
      headers: {'content-type': 'application/json; charset=utf-8'},
    );

Map<String, dynamic> _solicitud({bool conTimeline = true}) => {
      'id': 4,
      'estado': 'autorizada',
      'estado_etiqueta': 'Autorizada',
      'pedido_id': 7,
      'producto': {'nombre': 'Bota Nobuk', 'modelo': 'B-1', 'talla': '25', 'color': 'Café', 'cantidad': 1},
      'motivo': 'Me quedó chica',
      'fecha_solicitud': '2026-10-05T18:00:00+00:00',
      if (conTimeline)
        'timeline': [
          {
            'id': 1,
            'estado': 'solicitada',
            'etiqueta': 'Solicitada',
            'estado_anterior': null,
            'nota': 'Me quedó chica',
            'fecha': '2026-10-05T18:00:00+00:00',
            'fecha_cdmx': '05/10/2026 12:00',
            'quien': 'Cliente',
          },
          {
            'id': 2,
            'estado': 'autorizada',
            'etiqueta': 'Autorizada',
            'estado_anterior': 'solicitada',
            'nota': 'Pasa a recoger',
            'fecha': '2026-10-06T20:15:00+00:00',
            'fecha_cdmx': '06/10/2026 14:15',
            'quien': 'Ana (distribuidora)',
          },
        ],
    };

Widget _app(MockClient servidor, Widget pantalla) => ChangeNotifierProvider(
      create: (_) => AuthProvider(api: ApiService(cliente: servidor)),
      child: MaterialApp(home: pantalla),
    );

void main() {
  setUp(() => FlutterSecureStorage.setMockInitialValues(<String, String>{'token_sanctum': '1|token'}));

  group('modelo', () {
    test('lee la línea de tiempo con hora de CDMX y quién', () {
      final s = SolicitudCambio.desdeJson(_solicitud());
      expect(s.pasos, hasLength(2));
      expect(s.pasos[1].quien, 'Ana (distribuidora)');
      expect(s.pasos[1].estadoAnterior, 'solicitada');
      expect(s.pasos[1].fechaCdmx, '06/10/2026 14:15');
      expect(s.terminada, isFalse);
    });

    test('sin fecha_cdmx del servidor la calcula en UTC-6', () {
      expect(formatearCdmx(DateTime.parse('2026-10-06T20:15:00Z')), '06/10/2026 14:15');
      expect(formatearCdmx(DateTime.parse('2026-01-01T03:00:00Z')), '31/12/2025 21:00');
    });

    test('reconoce el push de una solicitud de cambio', () {
      expect(solicitudCambioDePush({'entidad_tipo': 'solicitud_cambio', 'entidad_id': '4'}), 4);
      expect(solicitudCambioDePush({'entidad_tipo': 'pedido', 'entidad_id': '4'}), isNull);
      expect(solicitudCambioDePush({'entidad_tipo': 'solicitud_cambio', 'entidad_id': 'x'}), isNull);
    });
  });

  testWidgets('muestra cada paso con fecha, quién y nota', (tester) async {
    final servidor = MockClient((peticion) async {
      if (peticion.url.path.endsWith('/solicitudes-cambio/4')) return _json({'data': _solicitud()}, 200);
      return _json({'message': 'No encontrado'}, 404);
    });

    await tester.pumpWidget(_app(servidor, const HistorialCambioScreen(solicitudId: 4)));
    await tester.pump();
    await tester.pump(const Duration(seconds: 2));

    expect(find.text('Bota Nobuk'), findsOneWidget);
    expect(find.text('Solicitada'), findsOneWidget);
    expect(find.text('06/10/2026 14:15'), findsOneWidget);
    expect(find.text('Ana (distribuidora)'), findsOneWidget);
    expect(find.text('Pasa a recoger'), findsOneWidget);
  });

  testWidgets('404: dice que no la encontró', (tester) async {
    final servidor = MockClient((_) async => _json({'message': 'No encontrado'}, 404));

    await tester.pumpWidget(_app(servidor, const HistorialCambioScreen(solicitudId: 99)));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 100));

    expect(find.text('No encontramos esta solicitud de cambio.'), findsOneWidget);
  });

  testWidgets('desde Mis cambios se abre el historial', (tester) async {
    final servidor = MockClient((peticion) async {
      final ruta = peticion.url.path;
      if (ruta.endsWith('/solicitudes-cambio')) return _json({'data': [_solicitud(conTimeline: false)]}, 200);
      if (ruta.endsWith('/solicitudes-cambio/4')) return _json({'data': _solicitud()}, 200);
      return _json({'message': 'No encontrado'}, 404);
    });

    await tester.pumpWidget(_app(servidor, const MisCambiosScreen()));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 100));

    await tester.tap(find.text('Bota Nobuk'));
    await tester.pump();
    await tester.pump(const Duration(seconds: 2));

    expect(find.text('Historial del cambio'), findsOneWidget);
    expect(find.text('Pasa a recoger'), findsOneWidget);
  });
}
