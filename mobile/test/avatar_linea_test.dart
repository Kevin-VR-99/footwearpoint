import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:footwearpoint/widgets/avatar_linea.dart';

/// TG-386 A26: el logo de la línea o su inicial, como x-ui.avatar-linea en la web.
void main() {
  Widget envolver(Widget hijo) => MaterialApp(home: Scaffold(body: Center(child: hijo)));

  test('la inicial es una sola letra en mayúscula', () {
    expect(FpAvatarLinea.inicial('impuls'), 'I');
    expect(FpAvatarLinea.inicial('  Destroyer Kids '), 'D');
    expect(FpAvatarLinea.inicial('Ñandú'), 'Ñ');
    expect(FpAvatarLinea.inicial('   '), '?');
  });

  testWidgets('sin logo enseña la inicial y no la lee en voz alta', (tester) async {
    final semantica = tester.ensureSemantics();
    await tester.pumpWidget(envolver(const FpAvatarLinea(nombre: 'Cklass')));

    expect(find.text('C'), findsOneWidget);
    expect(find.byType(Image), findsNothing);
    expect(find.bySemanticsLabel('C'), findsNothing);
    semantica.dispose();
  });

  testWidgets('una URL vacía cuenta como sin logo', (tester) async {
    await tester.pumpWidget(envolver(const FpAvatarLinea(nombre: 'Cklass', logotipoUrl: '  ')));

    expect(find.text('C'), findsOneWidget);
    expect(find.byType(Image), findsNothing);
  });

  testWidgets('con logo pinta la imagen entera y con su texto alternativo', (tester) async {
    await tester.pumpWidget(
      envolver(const FpAvatarLinea(nombre: 'Impuls', logotipoUrl: 'https://cdn.ejemplo.test/impuls.png')),
    );

    final imagen = tester.widget<Image>(find.byType(Image));
    expect(imagen.semanticLabel, 'Logotipo de Impuls');
    expect(imagen.fit, BoxFit.contain);
    expect((imagen.image as NetworkImage).url, 'https://cdn.ejemplo.test/impuls.png');
  });

  testWidgets('si el logo no carga, vuelve a la inicial', (tester) async {
    await tester.pumpWidget(
      envolver(const FpAvatarLinea(nombre: 'Impuls', logotipoUrl: 'https://cdn.ejemplo.test/roto.png')),
    );
    final imagen = tester.widget<Image>(find.byType(Image));
    final respaldo = imagen.errorBuilder!(tester.element(find.byType(Image)), Exception('404'), null);
    await tester.pumpWidget(envolver(respaldo));

    expect(find.text('I'), findsOneWidget);
  });
}
