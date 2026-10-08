import 'package:flutter_test/flutter_test.dart';
import 'package:footwearpoint/models/producto_catalogo.dart';
import 'package:footwearpoint/screens/producto_detalle_screen.dart';

/// Un producto con la forma de docs/contrato-api.md (sección 3). Cada prueba
/// cambia solo lo que le interesa.
Map<String, dynamic> productoJson({
  int id = 12,
  String nombre = 'Botín casual',
  Map<String, dynamic>? linea = const {'id': 2, 'nombre': 'Dama'},
  bool conMayorista = true,
  List<Map<String, dynamic>> imagenes = const [],
  List<Map<String, dynamic>>? variantes,
}) {
  return {
    'id': id,
    'producto': {
      'id': 4,
      'modelo': 'MOD-01',
      'nombre': nombre,
      'marca': {'id': 1, 'nombre': 'Flexi'},
      'linea': linea,
      'categoria': {'id': 3, 'nombre': 'Botines'},
    },
    'codigo_catalogo': 'CAT-001',
    'precio_minorista_sugerido': 800,
    if (conMayorista) 'precio_mayorista': 500.0,
    'imagenes': imagenes,
    'variantes': variantes ??
        [
          {
            'variante_id': 9,
            'sku': 'SKU-9',
            'talla': '24',
            'color': 'Negro',
            'nombre_color_comercial': 'Negro humo',
            'disponibilidad': 'disponible',
          },
        ],
  };
}

Map<String, dynamic> variante(int id, String talla, String color, String disponibilidad) => {
  'variante_id': id,
  'sku': 'SKU-$id',
  'talla': talla,
  'color': color,
  'nombre_color_comercial': null,
  'disponibilidad': disponibilidad,
};

void main() {
  group('leer el JSON del catálogo', () {
    test('lee todos los campos del contrato', () {
      final producto = ProductoCatalogo.desdeJson(productoJson());

      expect(producto.id, 12);
      expect(producto.productoId, 4);
      expect(producto.nombre, 'Botín casual');
      expect(producto.marca!.nombre, 'Flexi');
      expect(producto.linea!.nombre, 'Dama');
      expect(producto.codigoCatalogo, 'CAT-001');
      // Laravel puede mandar 800 (entero) aunque el precio sea decimal.
      expect(producto.precioMinoristaSugerido, 800.0);
      expect(producto.precioMayorista, 500.0);

      final v = producto.variantes.single;
      expect(v.varianteId, 9);
      expect(v.talla, '24');
      expect(v.colorParaMostrar, 'Negro humo');
      expect(v.disponibilidad, Disponibilidad.disponible);
    });

    test('para un cliente directo la llave precio_mayorista no viene: queda en null', () {
      final producto = ProductoCatalogo.desdeJson(productoJson(conMayorista: false));

      expect(producto.precioMayorista, isNull);
    });

    test('un producto sin línea se puede leer', () {
      final producto = ProductoCatalogo.desdeJson(productoJson(linea: null));

      expect(producto.linea, isNull);
    });

    test('las tres disponibilidades del backend', () {
      expect(Disponibilidad.desdeJson('disponible'), Disponibilidad.disponible);
      expect(Disponibilidad.desdeJson('bajo_pedido'), Disponibilidad.bajoPedido);
      expect(Disponibilidad.desdeJson('no_disponible'), Disponibilidad.noDisponible);
      // Un estado desconocido nunca se ofrece como disponible.
      expect(Disponibilidad.desdeJson('otro'), Disponibilidad.noDisponible);
    });
  });

  group('imágenes', () {
    test('sin imágenes no hay principal', () {
      expect(ProductoCatalogo.desdeJson(productoJson()).imagenPrincipal, isNull);
    });

    test('usa la marcada como principal, o si no, la de menor orden', () {
      final conPrincipal = ProductoCatalogo.desdeJson(productoJson(imagenes: [
        {'id': 1, 'url': 'https://x/1.jpg', 'orden': 1, 'es_principal': false},
        {'id': 2, 'url': 'https://x/2.jpg', 'orden': 2, 'es_principal': true},
      ]));
      expect(conPrincipal.imagenPrincipal!.id, 2);
      expect(conPrincipal.imagenesOrdenadas.map((i) => i.id), [2, 1]);

      final sinPrincipal = ProductoCatalogo.desdeJson(productoJson(imagenes: [
        {'id': 5, 'url': 'https://x/5.jpg', 'orden': 3, 'es_principal': false},
        {'id': 6, 'url': 'https://x/6.jpg', 'orden': 1, 'es_principal': false},
      ]));
      expect(sinPrincipal.imagenPrincipal!.id, 6);
    });
  });

  group('variantes', () {
    test('se agrupan por color y las tallas quedan de menor a mayor', () {
      final producto = ProductoCatalogo.desdeJson(productoJson(variantes: [
        variante(1, '25', 'Negro', 'disponible'),
        variante(2, '23.5', 'Negro', 'bajo_pedido'),
        variante(3, '24', 'Café', 'no_disponible'),
        variante(4, '23', 'Negro', 'disponible'),
      ]));

      final grupos = producto.variantesPorColor;

      expect(grupos.keys, ['Negro', 'Café']);
      expect(grupos['Negro']!.map((v) => v.talla), ['23', '23.5', '25']);
    });

    test('el resumen toma la mejor disponibilidad entre sus variantes', () {
      ProductoCatalogo con(List<String> estados) => ProductoCatalogo.desdeJson(productoJson(
        variantes: [
          for (var i = 0; i < estados.length; i++) variante(i, '2$i', 'Negro', estados[i]),
        ],
      ));

      expect(con(['no_disponible', 'bajo_pedido']).mejorDisponibilidad, Disponibilidad.bajoPedido);
      expect(con(['bajo_pedido', 'disponible']).mejorDisponibilidad, Disponibilidad.disponible);
      expect(con(['no_disponible']).mejorDisponibilidad, Disponibilidad.noDisponible);
      expect(con([]).mejorDisponibilidad, isNull);
    });
  });

  test('agrupar por línea: orden alfabético y "Sin línea" al final', () {
    final productos = [
      productoJson(id: 1, nombre: 'Zapato B', linea: {'id': 5, 'nombre': 'Impuls'}),
      productoJson(id: 2, nombre: 'Sin linea', linea: null),
      productoJson(id: 3, nombre: 'Zapato A', linea: {'id': 5, 'nombre': 'Impuls'}),
      productoJson(id: 4, nombre: 'Sandalia', linea: {'id': 1, 'nombre': 'Andrea'}),
    ].map(ProductoCatalogo.desdeJson).toList();

    final lineas = LineaCatalogo.agrupar(productos);

    expect(lineas.map((l) => l.nombre), ['Andrea', 'Impuls', LineaCatalogo.nombreSinLinea]);
    expect(lineas[1].productos.map((p) => p.nombre), ['Zapato A', 'Zapato B']);
    expect(lineas.last.id, isNull);
  });

  test('formato de precio', () {
    expect(formatoPrecio(800), r'$800.00');
    expect(formatoPrecio(1600.5), r'$1,600.50');
    expect(formatoPrecio(1234567), r'$1,234,567.00');
  });
}
