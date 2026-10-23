import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:footwearpoint/providers/auth_provider.dart';
import 'package:footwearpoint/screens/pago_mercado_pago_screen.dart';
import 'package:footwearpoint/screens/pedido_detalle_screen.dart';
import 'package:footwearpoint/services/api_service.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:provider/provider.dart';

/// TG-268 (E11-01) y TG-118 (E8-02): pagar con Mercado Pago desde la app.

http.Response _json(Object cuerpo, int codigo) => http.Response(
  jsonEncode(cuerpo),
  codigo,
  headers: {'content-type': 'application/json; charset=utf-8'},
);

const _initPoint = 'https://www.mercadopago.com.mx/checkout/v1/redirect?pref_id=PREF-1';

/// El pedido como lo manda PedidoResource, con los campos de Mercado Pago.
Map<String, dynamic> _pedido({
  String tipo = 'cliente_directo',
  double saldo = 1600,
  double anticipoPendiente = 200,
  bool anticipo = false,
  bool saldoMp = false,
  bool total = false,
  String? pendiente,
}) => {
  'id': 5,
  'folio': 'PED-005',
  'tipo': tipo,
  'estado': 'colocado',
  'total': 1600.0,
  'pagado': 1600.0 - saldo,
  'pagado_con_vales': 0.0,
  'saldo': saldo,
  'anticipo_requerido': 200.0,
  'anticipo_pagado': 200.0 - anticipoPendiente,
  'anticipo_pendiente': anticipoPendiente,
  'lineas': <Object>[],
  'pagos': [
    if (pendiente != null)
      {'folio': 'PAG-9', 'tipo': pendiente, 'metodo': 'mercado_pago', 'monto': 200.0, 'estado': 'pendiente'},
  ],
  'puede_pagar_anticipo_mercado_pago': anticipo,
  'puede_pagar_saldo_mercado_pago': saldoMp,
  'puede_pagar_total_mercado_pago': total,
  'pago_mercado_pago_pendiente': pendiente != null,
  'pago_mercado_pago_pendiente_tipo': pendiente,
};

class _Servidor {
  _Servidor({
    required this.pedido,
    this.resultado = 'aplicado',
    this.mensaje = 'Recibimos el pago de tu pedido. ¡Gracias!',
    this.errorAlCrear,
  });

  Map<String, dynamic> pedido;
  final String resultado;
  final String mensaje;
  final String? errorAlCrear;
  final rutas = <String>[];

  MockClient get cliente => MockClient((peticion) async {
    final ruta = peticion.url.path.replaceFirst('/api', '');
    rutas.add('${peticion.method} $ruta');

    if (ruta == '/pedidos/5' && peticion.method == 'GET') return _json({'data': pedido}, 200);
    if (ruta.endsWith('/mercado-pago/verificar')) {
      return _json({'data': pedido, 'resultado': resultado, 'message': mensaje}, 200);
    }
    if (ruta.endsWith('/mercado-pago')) {
      if (errorAlCrear != null) return _json({'message': errorAlCrear}, 422);
      return _json({
        'data': {'pago_id': 9, 'monto': 200.0, 'init_point': _initPoint, 'reutilizado': false},
        'message': 'Abre el enlace para pagar tu pedido con Mercado Pago.',
      }, 201);
    }
    return _json({'message': 'No encontrado.'}, 404);
  });
}

void main() {
  late List<Uri> abiertos;

  setUp(() {
    FlutterSecureStorage.setMockInitialValues(<String, String>{'token_sanctum': '1|token'});
    abiertos = [];
    PagoMercadoPagoScreen.abrirEnlacePorDefecto = (enlace) async {
      abiertos.add(enlace);
      return true;
    };
  });

  Future<void> abrir(WidgetTester tester, _Servidor servidor) async {
    await tester.pumpWidget(
      ChangeNotifierProvider(
        create: (_) => AuthProvider(api: ApiService(cliente: servidor.cliente)),
        child: const MaterialApp(home: PedidoDetalleScreen(pedidoId: 5)),
      ),
    );
    await tester.pumpAndSettle();
  }

  Future<void> tocar(WidgetTester tester, String texto) async {
    await tester.ensureVisible(find.text(texto));
    await tester.tap(find.text(texto));
    await tester.pumpAndSettle();
  }

  Future<void> regresarALaApp(WidgetTester tester) async {
    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.inactive);
    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.paused);
    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.inactive);
    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
    await tester.pumpAndSettle();
  }

  testWidgets('sin nada que pagar con Mercado Pago, solo menciona el mostrador', (tester) async {
    await abrir(tester, _Servidor(pedido: _pedido()));

    expect(find.text('Los pagos se hacen y se registran en mostrador.'), findsOneWidget);
    expect(find.textContaining('Mercado Pago'), findsNothing);
    expect(find.text('Verificar pago'), findsNothing);
  });

  testWidgets('el cliente directo paga su anticipo y al regresar se verifica solo', (tester) async {
    final servidor = _Servidor(pedido: _pedido(anticipo: true));
    await abrir(tester, servidor);

    expect(find.text('Puedes pagar con tarjeta o en mostrador.'), findsOneWidget);
    await tocar(tester, 'Pagar anticipo con tarjeta');

    expect(find.text('Anticipo'), findsOneWidget);
    expect(find.text(r'$200.00'), findsOneWidget);
    await tester.tap(find.byKey(const Key('abrir_pago_tarjeta')));
    await tester.pumpAndSettle();

    expect(servidor.rutas, contains('POST /pedidos/5/anticipo/mercado-pago'));
    expect(abiertos, [Uri.parse(_initPoint)]);
    expect(find.text('Verificar pago'), findsOneWidget);

    servidor.pedido = _pedido(saldo: 1400, anticipoPendiente: 0);
    await regresarALaApp(tester);

    expect(servidor.rutas, contains('POST /pedidos/5/anticipo/mercado-pago/verificar'));
    expect(find.text('Recibimos el pago de tu pedido. ¡Gracias!'), findsOneWidget);

    await tocar(tester, 'Volver al pedido');
    // Se recarga el pedido al volver.
    expect(servidor.rutas.where((r) => r == 'GET /pedidos/5').length, 2);
    expect(find.text('Pagar anticipo con tarjeta'), findsNothing);
  });

  testWidgets('si se vuelve a la app antes de que el navegador suelte la llamada, igual verifica', (tester) async {
    final servidor = _Servidor(pedido: _pedido(anticipo: true));
    PagoMercadoPagoScreen.abrirEnlacePorDefecto = (enlace) async {
      tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.paused);
      tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
      return true;
    };
    await abrir(tester, servidor);

    await tocar(tester, 'Pagar anticipo con tarjeta');
    await tester.tap(find.byKey(const Key('abrir_pago_tarjeta')));
    await tester.pumpAndSettle();

    expect(servidor.rutas, contains('POST /pedidos/5/anticipo/mercado-pago/verificar'));
    expect(find.text('Recibimos el pago de tu pedido. ¡Gracias!'), findsOneWidget);
  });

  testWidgets('el cliente mayorista paga el total de su pedido', (tester) async {
    final servidor = _Servidor(pedido: _pedido(tipo: 'revendedor', total: true));
    await abrir(tester, servidor);

    await tocar(tester, 'Pagar con tarjeta');
    expect(find.text('Total del pedido'), findsWidgets);
    await tester.tap(find.byKey(const Key('abrir_pago_tarjeta')));
    await tester.pumpAndSettle();

    expect(servidor.rutas, contains('POST /pedidos/5/total/mercado-pago'));
    expect(abiertos, hasLength(1));
  });

  testWidgets('un pago pendiente se verifica con su ruta y puede seguir pendiente', (tester) async {
    final servidor = _Servidor(
      pedido: _pedido(anticipoPendiente: 0, pendiente: 'saldo_pedido'),
      resultado: 'pendiente',
      mensaje: 'Tu pago todavía no se confirma. Revisa de nuevo en unos minutos.',
    );
    await abrir(tester, servidor);

    expect(find.textContaining('Mercado Pago · Por confirmar'), findsOneWidget);
    await tocar(tester, 'Verificar pago');

    expect(servidor.rutas, contains('POST /pedidos/5/saldo/mercado-pago/verificar'));
    expect(find.text('Tu pago todavía no se confirma. Revisa de nuevo en unos minutos.'), findsOneWidget);
    expect(find.text('Verificar pago'), findsOneWidget);
    expect(abiertos, isEmpty);
  });

  testWidgets('si el pago no cuadra, no ofrece pagar otra vez', (tester) async {
    final servidor = _Servidor(
      pedido: _pedido(anticipo: true, pendiente: 'anticipo'),
      resultado: 'no_cuadra',
      mensaje: 'Mercado Pago tiene un pago que no coincide con este pedido, así que no se aplicó.',
    );
    await abrir(tester, servidor);

    await tocar(tester, 'Verificar pago');

    expect(find.textContaining('no coincide'), findsOneWidget);
    expect(find.byKey(const Key('abrir_pago_tarjeta')), findsNothing);
    expect(find.text('Abrir el pago con tarjeta otra vez'), findsNothing);
    expect(find.text('Volver al pedido'), findsOneWidget);
  });

  testWidgets('si Mercado Pago rechazó el pago, deja intentarlo de nuevo', (tester) async {
    final servidor = _Servidor(
      pedido: _pedido(anticipo: true, pendiente: 'anticipo'),
      resultado: 'rechazado',
      mensaje: 'Mercado Pago rechazó el pago. Puedes intentarlo de nuevo con otro medio de pago.',
    );
    await abrir(tester, servidor);

    await tocar(tester, 'Verificar pago');

    expect(find.textContaining('rechazó el pago'), findsOneWidget);
    expect(find.byKey(const Key('abrir_pago_tarjeta')), findsOneWidget);
  });

  testWidgets('el error del servidor se muestra tal cual', (tester) async {
    final servidor = _Servidor(
      pedido: _pedido(anticipo: true),
      errorAlCrear: 'Esta distribuidora todavía no recibe pagos con Mercado Pago. Puedes pagar en mostrador.',
    );
    await abrir(tester, servidor);

    await tocar(tester, 'Pagar anticipo con tarjeta');
    await tester.tap(find.byKey(const Key('abrir_pago_tarjeta')));
    await tester.pumpAndSettle();

    expect(find.text('Esta distribuidora todavía no recibe pagos con Mercado Pago. Puedes pagar en mostrador.'), findsOneWidget);
    expect(abiertos, isEmpty);
  });
}
