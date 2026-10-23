import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:footwearpoint/models/filtro_catalogo.dart';
import 'package:footwearpoint/models/producto_catalogo.dart';
import 'package:footwearpoint/providers/auth_provider.dart';
import 'package:footwearpoint/screens/pedido_detalle_screen.dart';
import 'package:footwearpoint/services/api_service.dart';
import 'package:footwearpoint/services/aperturas_push.dart';
import 'package:footwearpoint/services/compartir_whatsapp.dart';
import 'package:footwearpoint/services/enlaces_app.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:provider/provider.dart';

ProductoCatalogo _producto({
  required int id,
  required String marca,
  required String categoria,
  required String talla,
  required String disponibilidad,
}) {
  return ProductoCatalogo.desdeJson({
    'id': id,
    'producto': {
      'id': id + 10,
      'modelo': 'M$id',
      'nombre': 'Modelo $id',
      'marca': {'id': 1, 'nombre': marca},
      'linea': {'id': 1, 'nombre': 'Linea'},
      'categoria': {'id': 1, 'nombre': categoria},
    },
    'codigo_catalogo': 'C$id',
    'precio_minorista_sugerido': 100,
    'variantes': [
      {
        'variante_id': id,
        'sku': 'S$id',
        'talla': talla,
        'color': 'Negro',
        'disponibilidad': disponibilidad,
      },
    ],
  });
}

void main() {
  test('filtra por marca, categoría, talla y separa disponible de bajo pedido', () {
    final productos = [
      _producto(id: 1, marca: 'Flexi', categoria: 'Botines', talla: '24', disponibilidad: 'disponible'),
      _producto(id: 2, marca: 'Flexi', categoria: 'Sandalias', talla: '22', disponibilidad: 'bajo_pedido'),
      _producto(id: 3, marca: 'Andrea', categoria: 'Botines', talla: '24', disponibilidad: 'disponible'),
    ];

    expect(
      aplicarFiltroCatalogo(productos, const FiltroCatalogo(marca: 'Flexi')).map((p) => p.id),
      [1, 2],
    );
    expect(
      aplicarFiltroCatalogo(productos, const FiltroCatalogo(categoria: 'Botines', talla: '24')).map((p) => p.id),
      [1, 3],
    );
    expect(
      aplicarFiltroCatalogo(productos, const FiltroCatalogo(disponibilidad: 'bajo_pedido')).map((p) => p.id),
      [2],
    );
    expect(
      aplicarFiltroCatalogo(productos, const FiltroCatalogo(disponibilidad: 'disponible')).map((p) => p.id),
      [1, 3],
    );
  });

  test('el enlace de whatsapp abre pedido o producto', () {
    expect(destinoDeEnlace(Uri.parse('https://footwearpoint.app/abrir/pedido/15'))?.id, 15);
    expect(destinoDeEnlace(Uri.parse('https://footwearpoint.app/abrir/pedido/15'))?.esProducto, isFalse);
    expect(destinoDeEnlace(Uri.parse('https://footwearpoint.app/abrir/producto/8'))?.esProducto, isTrue);
    expect(destinoDeEnlace(Uri.parse('https://example.com/abrir/pedido/15')), isNull);
    expect(destinoDeEnlace(Uri.parse('https://footwearpoint.app/abrir/perfil'))?.esPerfil, isTrue);
    expect(destinoDeEnlace(Uri.parse('https://footwearpoint.app/abrir/perfil/9')), isNull);
    expect(conEnlaceDeApp('Hola', enlaceAbrirPedido(4)), 'Hola\nAbrir en la app: https://footwearpoint.app/abrir/pedido/4');
    expect(conEnlaceDeApp('https://footwearpoint.app/abrir/pedido/4', enlaceAbrirPedido(4)), 'https://footwearpoint.app/abrir/pedido/4');
  });

  test('un push de pedido reconoce el id y uno de cambio no se confunde', () {
    expect(pedidoDePush({'entidad_tipo': 'pedido', 'entidad_id': '9'}), 9);
    expect(pedidoDePush({'entidad_tipo': 'solicitud_cambio', 'entidad_id': '9'}), isNull);
    expect(solicitudCambioDePush({'entidad_tipo': 'solicitud_cambio', 'entidad_id': '4'}), 4);
  });

  testWidgets('con la app abierta el push se muestra y abre el pedido', (tester) async {
    tester.view.physicalSize = const Size(800, 1000);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);
    FlutterSecureStorage.setMockInitialValues(<String, String>{'token_sanctum': '1|token'});
    final api = ApiService(
      cliente: MockClient((peticion) async => http.Response(
        jsonEncode({
          'data': {
            'id': 5,
            'folio': 'PED-5',
            'tipo': 'cliente_directo',
            'estado': 'colocado',
            'total': 100.0,
            'pagado': 0.0,
            'pagado_con_vales': 0.0,
            'saldo': 100.0,
            'anticipo_requerido': 0.0,
            'anticipo_pagado': 0.0,
            'anticipo_pendiente': 0.0,
            'lineas': <Object>[],
            'pagos': <Object>[],
          },
        }),
        200,
        headers: {'content-type': 'application/json'},
      )),
    );

    await tester.pumpWidget(
      ChangeNotifierProvider(
        create: (_) => AuthProvider(api: api),
        child: MaterialApp(
          navigatorKey: navegadorRaiz,
          home: const Scaffold(body: Text('Inicio')),
        ),
      ),
    );

    AperturasPush.mostrarAviso(
      titulo: 'Pago recibido',
      cuerpo: 'Tu pedido ya está pagado.',
      datos: {'entidad_tipo': 'pedido', 'entidad_id': '5'},
    );
    await tester.pump();

    expect(find.text('Pago recibido. Tu pedido ya está pagado.'), findsOneWidget);
    tester.widget<SnackBarAction>(find.byType(SnackBarAction)).onPressed();
    await tester.pumpAndSettle();

    expect(find.byType(PedidoDetalleScreen), findsOneWidget);
  });
}
