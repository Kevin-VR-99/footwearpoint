import 'package:flutter_test/flutter_test.dart';
import 'package:footwearpoint/models/vale.dart';
import 'package:footwearpoint/widgets/selector_vale.dart';

/// TG-356: el vale con tipo avisa antes de usarse que se consume completo.
void main() {
  Map<String, dynamic> json(String? tipo) => {
        'id': 1,
        'folio': 'VAL-1',
        'monto_original': '100.00',
        'saldo_actual': '100.00',
        'estado': 'activo',
        'tipo': ?tipo,
      };

  test('vale anterior sin tipo no cambia', () {
    final vale = Vale.fromJson(json(null));
    expect(vale.tipo, isNull);
    expect(vale.seUsaCompleto, isFalse);
  });

  test('nota de devolución y sobrante avisan uso completo', () {
    for (final tipo in ['devolucion_producto', 'sobrante']) {
      final vale = Vale.fromJson(json(tipo));
      expect(vale.seUsaCompleto, isTrue);
      expect(avisoUsoCompleto(vale), contains('se usa completo'));
      expect(avisoUsoCompleto(vale), contains('vale nuevo por la diferencia'));
    }
  });

  test('anticipo avisa que solo cubre anticipo pendiente', () {
    final vale = Vale.fromJson(json('anticipo'));
    expect(avisoUsoCompleto(vale), contains('anticipo pendiente'));
  });
}
