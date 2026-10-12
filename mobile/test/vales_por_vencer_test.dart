import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:footwearpoint/models/vale.dart';
import 'package:footwearpoint/providers/auth_provider.dart';
import 'package:footwearpoint/screens/vales_screen.dart';
import 'package:footwearpoint/services/api_service.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:provider/provider.dart';

/// TG-122: «Vence pronto» en Mis vales, con el margen que decide el servidor.

http.Response _json(Object cuerpo, int codigo) => http.Response(
  jsonEncode(cuerpo),
  codigo,
  headers: {'content-type': 'application/json; charset=utf-8'},
);

Map<String, dynamic> _vale(int id, String folio, {required int dias, bool? porVencer}) => {
  'id': id,
  'folio': folio,
  'monto_original': 500.0,
  'saldo_actual': 300.0,
  'fecha_vencimiento': DateTime.now().add(Duration(days: dias)).toIso8601String(),
  'estado': 'activo',
  'por_vencer': ?porVencer,
};

void main() {
  setUp(() => FlutterSecureStorage.setMockInitialValues(<String, String>{'token_sanctum': '1|token'}));

  test('lee por_vencer y sin el campo queda en false', () {
    expect(Vale.fromJson(_vale(1, 'V-1', dias: 3, porVencer: true)).porVencer, isTrue);
    expect(Vale.fromJson(_vale(2, 'V-2', dias: 3)).porVencer, isFalse);
  });

  testWidgets('resalta solo los vales que vencen pronto', (tester) async {
    final servidor = MockClient((peticion) async {
      if (peticion.url.path.endsWith('/vales')) {
        return _json({
          'data': [
            _vale(1, 'V-PRONTO', dias: 3, porVencer: true),
            _vale(2, 'V-LEJOS', dias: 60, porVencer: false),
          ],
        }, 200);
      }
      return _json({'data': <Object>[]}, 200);
    });

    await tester.pumpWidget(
      ChangeNotifierProvider(
        create: (_) => AuthProvider(api: ApiService(cliente: servidor)),
        child: const MaterialApp(home: ValesScreen()),
      ),
    );
    await tester.pumpAndSettle();

    expect(find.text('Folio: V-PRONTO'), findsOneWidget);
    expect(find.text('Folio: V-LEJOS'), findsOneWidget);
    expect(find.text('Vence pronto'), findsOneWidget);

    // La insignia está en la tarjeta de V-PRONTO.
    final tarjeta = find.ancestor(of: find.text('Vence pronto'), matching: find.byType(Card));
    expect(find.descendant(of: tarjeta, matching: find.text('Folio: V-PRONTO')), findsOneWidget);
  });
}
