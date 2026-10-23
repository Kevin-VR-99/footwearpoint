import 'package:flutter_test/flutter_test.dart';
import 'package:footwearpoint/models/filtro_catalogo.dart';
import 'package:footwearpoint/models/producto_catalogo.dart';

ProductoCatalogo _producto(int id, Map<String, String> tallas) {
  var n = 0;
  return ProductoCatalogo.desdeJson({
    'id': id,
    'producto': {
      'id': id + 10,
      'modelo': 'M$id',
      'nombre': 'Modelo $id',
      'marca': {'id': 1, 'nombre': 'Impuls'},
      'linea': {'id': 1, 'nombre': 'Impuls'},
      'categoria': {'id': 1, 'nombre': 'Tenis'},
    },
    'codigo_catalogo': 'C$id',
    'precio_minorista_sugerido': 100,
    'variantes': [
      for (final entrada in tallas.entries)
        {
          'variante_id': id * 100 + n++,
          'sku': 'S$id-${entrada.key}',
          'talla': entrada.key,
          'color': 'Negro',
          'disponibilidad': entrada.value,
        },
    ],
  });
}

void main() {
  final mixto = _producto(1, {'22': 'disponible', '27': 'bajo_pedido', '25': 'no_disponible'});
  final soloDisponible = _producto(2, {'22': 'disponible', '27': 'disponible'});
  final productos = [mixto, soloDisponible];

  List<int> ids(FiltroCatalogo filtro) => aplicarFiltroCatalogo(productos, filtro).map((p) => p.id).toList();

  test('bajo pedido incluye modelos que tambien tienen tallas disponibles', () {
    expect(ids(const FiltroCatalogo(disponibilidad: 'bajo_pedido')), [1]);
    expect(ids(const FiltroCatalogo(disponibilidad: 'disponible')), [1, 2]);
    expect(ids(const FiltroCatalogo()), [1, 2]);
  });

  test('con talla elegida cuenta la disponibilidad de esa talla', () {
    expect(ids(const FiltroCatalogo(talla: '27', disponibilidad: 'bajo_pedido')), [1]);
    expect(ids(const FiltroCatalogo(talla: '27', disponibilidad: 'disponible')), [2]);
    expect(ids(const FiltroCatalogo(talla: '22', disponibilidad: 'bajo_pedido')), isEmpty);
    expect(ids(const FiltroCatalogo(talla: '25')), [1]);
  });

  test('las tallas del desplegable se ordenan como numeros', () {
    expect(opcionesDe(['3', '22', '2', '27', '22.5', 'CH'], comoTallas: true), ['2', '3', '22', '22.5', '27', 'CH']);
    expect(opcionesDe(['Impuls', 'Cklass']), ['Cklass', 'Impuls']);
  });
}