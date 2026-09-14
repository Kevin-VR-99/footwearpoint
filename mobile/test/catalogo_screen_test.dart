import 'dart:convert';
import 'dart:io';

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

Map<String, dynamic> _producto({
  required int id,
  required String nombre,
  required Map<String, dynamic>? linea,
  required List<Map<String, dynamic>> variantes,
  bool conMayorista = true,
}) {
  return {
    'id': id,
    'producto': {
      'id': id + 100,
      'modelo': 'MOD-$id',
      'nombre': nombre,
      'marca': {'id': 1, 'nombre': 'Flexi'},
      'linea': linea,
      'categoria': {'id': 3, 'nombre': 'Botines'},
    },
    'codigo_catalogo': 'CAT-$id',
    'precio_minorista_sugerido': 1600,
    if (conMayorista) 'precio_mayorista': 950.0,
    'imagenes': <Map<String, dynamic>>[],
    'variantes': variantes,
  };
}

Map<String, dynamic> _variante(int id, String talla, String color, String disponibilidad) => {
  'variante_id': id,
  'sku': 'SKU-$id',
  'talla': talla,
  'color': color,
  'nombre_color_comercial': null,
  'disponibilidad': disponibilidad,
};

/// El catálogo como lo manda el servidor. [conMayorista] = false imita lo que
/// recibe un cliente directo.
List<Map<String, dynamic>> _catalogo({bool conMayorista = true}) => [
  _producto(
    id: 1,
    nombre: 'Botín casual',
    linea: {'id': 2, 'nombre': 'Impuls'},
    conMayorista: conMayorista,
    variantes: [
      _variante(1, '24', 'Negro', 'disponible'),
      _variante(2, '25', 'Negro', 'bajo_pedido'),
      _variante(3, '23', 'Café', 'no_disponible'),
    ],
  ),
  _producto(
    id: 2,
    nombre: 'Sandalia',
    linea: {'id': 1, 'nombre': 'Andrea'},
    conMayorista: conMayorista,
    variantes: [_variante(4, '22', 'Blanco', 'bajo_pedido')],
  ),
];

void main() {
  setUp(() {
    FlutterSecureStorage.setMockInitialValues(<String, String>{
      'token_sanctum': '1|token-de-prueba',
    });
  });

  /// Abre la app con sesión (auth/me) y entra al catálogo desde inicio.
  /// [catalogo] decide qué responde GET /api/catalogo.
  Future<List<http.Request>> abrirCatalogo(
    WidgetTester tester,
    Future<http.Response> Function() catalogo, {
    String rol = 'revendedor',
  }) async {
    final peticiones = <http.Request>[];

    final servidor = MockClient((peticion) async {
      peticiones.add(peticion);

      if (peticion.url.path.endsWith('/auth/me')) {
        return _json({
          'data': {
            'usuario': {
              'id': 7,
              'nombre': 'María López',
              'email': 'maria.lopez@revendedor.test',
              'telefono': null,
              'estado': 'activo',
            },
            'rol': rol,
            'distribuidora_id': 1,
          },
        }, 200);
      }

      return catalogo();
    });

    await tester.pumpWidget(FootwearPointApp(api: ApiService(cliente: servidor)));
    await tester.pumpAndSettle();

    await tester.tap(find.text('Ver catálogo'));
    await tester.pumpAndSettle();

    return peticiones;
  }

  testWidgets('muestra las líneas en orden con cuántos productos tiene cada una', (tester) async {
    final peticiones = await abrirCatalogo(tester, () async => _json({'data': _catalogo()}, 200));

    expect(peticiones.where((p) => p.url.path.endsWith('/catalogo')), hasLength(1));
    expect(find.text('Elige una línea'), findsOneWidget);
    expect(find.text('Andrea'), findsOneWidget);
    expect(find.text('Impuls'), findsOneWidget);
    expect(find.text('1 producto'), findsNWidgets(2));

    // Andrea va antes que Impuls.
    expect(
      tester.getTopLeft(find.text('Andrea')).dy,
      lessThan(tester.getTopLeft(find.text('Impuls')).dy),
    );
  });

  testWidgets('línea -> productos -> detalle con tallas por color y su disponibilidad', (tester) async {
    await abrirCatalogo(tester, () async => _json({'data': _catalogo()}, 200));

    await tester.tap(find.text('Impuls'));
    await tester.pumpAndSettle();

    // Lista de productos de la línea.
    expect(find.text('Botín casual'), findsOneWidget);
    expect(find.text('Sandalia'), findsNothing);
    expect(find.text(r'$1,600.00'), findsOneWidget);
    expect(find.text(r'Mayorista: $950.00'), findsOneWidget);
    // El resumen toma la mejor de sus variantes.
    expect(find.text('Disponible'), findsOneWidget);

    await tester.tap(find.text('Botín casual'));
    await tester.pumpAndSettle();

    // Detalle.
    expect(find.text('Precio sugerido de venta'), findsOneWidget);
    expect(find.text('Precio mayorista'), findsOneWidget);
    expect(find.text('Tallas y colores'), findsOneWidget);
    expect(find.text('Negro'), findsOneWidget);
    expect(find.text('Café'), findsOneWidget);
    expect(find.byTooltip('Talla 24: Disponible'), findsOneWidget);
    expect(find.byTooltip('Talla 25: Bajo pedido'), findsOneWidget);
    expect(find.byTooltip('Talla 23: No disponible'), findsOneWidget);
  });

  testWidgets('un cliente directo no ve el precio mayorista en ninguna pantalla', (tester) async {
    await abrirCatalogo(
      tester,
      () async => _json({'data': _catalogo(conMayorista: false)}, 200),
      rol: 'cliente_directo',
    );

    await tester.tap(find.text('Impuls'));
    await tester.pumpAndSettle();
    expect(find.textContaining('Mayorista'), findsNothing);

    await tester.tap(find.text('Botín casual'));
    await tester.pumpAndSettle();
    expect(find.text('Precio sugerido de venta'), findsOneWidget);
    expect(find.textContaining('mayorista'), findsNothing);
  });

  testWidgets('catálogo vacío: lo dice en vez de dejar la pantalla en blanco', (tester) async {
    await abrirCatalogo(tester, () async => _json({'data': <Object>[]}, 200));

    expect(find.text('Por ahora no hay productos publicados en el catálogo.'), findsOneWidget);
  });

  testWidgets('sin conexión muestra el error y "Reintentar" vuelve a pedirlo', (tester) async {
    var hayRed = false;

    final peticiones = await abrirCatalogo(tester, () async {
      if (!hayRed) throw const SocketException('sin red');
      return _json({'data': _catalogo()}, 200);
    });

    expect(find.textContaining('No se pudo conectar con el servidor'), findsOneWidget);

    hayRed = true;
    await tester.tap(find.text('Reintentar'));
    await tester.pumpAndSettle();

    expect(find.text('Andrea'), findsOneWidget);
    expect(peticiones.where((p) => p.url.path.endsWith('/catalogo')), hasLength(2));
  });

  testWidgets('si el token ya no sirve (401), regresa al login con el aviso', (tester) async {
    await abrirCatalogo(tester, () async => _json({'message': 'Unauthenticated.'}, 401));

    expect(find.text('Entrar'), findsOneWidget);
    expect(find.text('Tu sesión expiró. Vuelve a iniciar sesión.'), findsOneWidget);
    expect(find.text('Catálogo'), findsNothing);
  });
}
