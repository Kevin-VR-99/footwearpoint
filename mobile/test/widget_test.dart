import 'package:flutter_test/flutter_test.dart';
import 'package:footwearpoint/main.dart';

void main() {
  testWidgets('la pantalla de login muestra sus campos y el botón', (tester) async {
    await tester.pumpWidget(const FootwearPointApp());

    expect(find.text('Correo'), findsOneWidget);
    expect(find.text('Contraseña'), findsOneWidget);
    expect(find.text('Entrar'), findsOneWidget);
  });

  testWidgets('con los campos vacíos no intenta entrar y avisa qué falta', (tester) async {
    await tester.pumpWidget(const FootwearPointApp());

    await tester.tap(find.text('Entrar'));
    await tester.pump();

    // Si la validación no frenara aquí, la prueba intentaría salir a la red.
    expect(find.text('Escribe tu correo.'), findsOneWidget);
    expect(find.text('Escribe tu contraseña.'), findsOneWidget);
  });
}
