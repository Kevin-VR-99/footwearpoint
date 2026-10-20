import 'package:flutter_test/flutter_test.dart';
import 'package:footwearpoint/models/cliente_privado_revendedor.dart';
import 'package:footwearpoint/models/pedido_resumen.dart';
import 'package:footwearpoint/models/producto_catalogo.dart';
import 'package:footwearpoint/models/vale.dart';
import 'package:footwearpoint/screens/clientes_privados_screen.dart';
import 'package:footwearpoint/screens/mis_pedidos_screen.dart';
import 'package:footwearpoint/screens/productos_linea_screen.dart';
import 'package:footwearpoint/screens/vales_screen.dart';

/// TG-281 (E19-11), punto 3: los buscadores de los cinco módulos.
///
/// Aquí se prueba el filtro, que es donde está la regla. Lo que se busca es
/// lo que la persona VE en pantalla: el estado de un pedido se busca por
/// «Listo para entrega» y no por `listo_entrega`, que es como lo llama el
/// servidor y que nadie escribiría.
ProductoCatalogo _producto({
  required int id,
  required String nombre,
  String modelo = 'MOD',
  String? marca,
  String codigo = 'CAT-1',
}) => ProductoCatalogo.desdeJson({
  'id': id,
  'producto': {
    'id': id,
    'modelo': modelo,
    'nombre': nombre,
    if (marca != null) 'marca': {'id': 1, 'nombre': marca},
    'linea': {'id': 2, 'nombre': 'Impuls'},
    'categoria': {'id': 3, 'nombre': 'Botines'},
  },
  'codigo_catalogo': codigo,
  'precio_minorista_sugerido': 800,
  'precio_mayorista': 500,
  'imagenes': <Object>[],
  'variantes': <Object>[],
});

PedidoResumen _pedido(int id, String folio, String estado) => PedidoResumen.desdeJson({
  'id': id,
  'folio': folio,
  'tipo': 'revendedor',
  'estado': estado,
  'total': 1000.0,
  'pagado': 0.0,
  'pagado_con_vales': 0.0,
  'saldo': 1000.0,
  'anticipo_requerido': 0.0,
  'anticipo_pagado': 0.0,
  'anticipo_pendiente': 0.0,
});

Vale _vale(int id, String folio) => Vale.fromJson({
  'id': id,
  'folio': folio,
  'monto_original': 500.0,
  'saldo_actual': 500.0,
  'fecha_emision': '2026-09-01T10:00:00-06:00',
  'fecha_vencimiento': '2099-11-30T10:00:00-06:00',
  'estado': 'activo',
});

ClientePrivadoRevendedor _cliente(int id, String nombre, {String? telefono, String? producto}) =>
    ClientePrivadoRevendedor.fromJson({
      'id': id,
      'revendedor_id': 1,
      'nombre': nombre,
      'telefono': telefono,
      'producto': producto,
    });

void main() {
  group('catálogo y líneas', () {
    final productos = [
      _producto(id: 1, nombre: 'Adidas Grand Court Blanco', modelo: 'JR4616', marca: 'Adidas'),
      _producto(id: 2, nombre: 'Casual HGN 1717 Blanco', modelo: '1717', marca: 'HGN by Mr Shu'),
      _producto(id: 3, nombre: 'Escolar Destroyer Negro', modelo: '1182', marca: 'Destroyer Kids'),
    ];

    test('sin nada escrito no filtra', () {
      expect(filtrarProductos(productos, ''), hasLength(3));
      expect(filtrarProductos(productos, '   '), hasLength(3));
    });

    test('encuentra por nombre, sin importar mayúsculas', () {
      expect(filtrarProductos(productos, 'ESCOLAR').single.nombre, contains('Escolar'));
    });

    test('encuentra por marca', () {
      expect(filtrarProductos(productos, 'destroyer kids'), hasLength(1));
    });

    test('encuentra por modelo', () {
      expect(filtrarProductos(productos, '1717').single.modelo, '1717');
    });

    test('varias palabras: tienen que estar todas, aunque sea en campos distintos', () {
      // "Adidas" es la marca y "Blanco" va en el nombre.
      expect(filtrarProductos(productos, 'adidas blanco'), hasLength(1));
      // Ninguno es a la vez Adidas y Negro.
      expect(filtrarProductos(productos, 'adidas negro'), isEmpty);
    });

    test('lo que no existe no devuelve nada', () {
      expect(filtrarProductos(productos, 'sandalia'), isEmpty);
    });
  });

  group('mis pedidos', () {
    final pedidos = [
      _pedido(1, 'PED-20261001-0001', 'colocado'),
      _pedido(2, 'PED-20261002-0002', 'listo_entrega'),
      _pedido(3, 'PED-20261003-0003', 'entregado'),
    ];

    test('encuentra por folio', () {
      expect(filtrarPedidos(pedidos, '0002').single.folio, 'PED-20261002-0002');
    });

    test('busca el estado como se lee en pantalla, no como lo llama el servidor', () {
      // La persona ve "Listo entrega"; nadie escribiría "listo_entrega".
      expect(filtrarPedidos(pedidos, 'listo entrega'), hasLength(1));
      // Busca por trozos de palabra, asi que "entrega" tambien encuentra
      // "Entregado". Es lo que se quiere: de otro modo habria que escribir
      // el estado entero y bien.
      expect(filtrarPedidos(pedidos, 'entrega'), hasLength(2));
      expect(filtrarPedidos(pedidos, 'listo_entrega'), isEmpty);
    });

    test('sin nada escrito los devuelve todos', () {
      expect(filtrarPedidos(pedidos, ''), hasLength(3));
    });
  });

  group('vales', () {
    final vales = [_vale(1, 'VAL-0001'), _vale(2, 'VAL-0002')];

    test('encuentra por folio', () {
      expect(filtrarVales(vales, 'val-0002').single.folio, 'VAL-0002');
    });

    test('sin nada escrito los devuelve todos', () {
      expect(filtrarVales(vales, ''), hasLength(2));
    });
  });

  group('mis clientes particulares', () {
    final clientes = [
      _cliente(1, 'Ana Ruiz', telefono: '9611112222', producto: 'Botín casual'),
      _cliente(2, 'Luis Pérez', telefono: '9613334444', producto: 'Sandalia playera'),
    ];

    test('encuentra por nombre', () {
      expect(filtrarClientes(clientes, 'ana').single.nombre, 'Ana Ruiz');
    });

    test('encuentra por teléfono', () {
      expect(filtrarClientes(clientes, '3334444').single.nombre, 'Luis Pérez');
    });

    test('encuentra por lo que le vendió', () {
      expect(filtrarClientes(clientes, 'sandalia').single.nombre, 'Luis Pérez');
    });

    test('aguanta clientes sin teléfono ni producto', () {
      final sinDatos = [_cliente(3, 'Rosa')];
      expect(filtrarClientes(sinDatos, 'rosa'), hasLength(1));
      expect(filtrarClientes(sinDatos, 'botín'), isEmpty);
    });
  });
}
