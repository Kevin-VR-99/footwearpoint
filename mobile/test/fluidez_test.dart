import 'package:flutter/cupertino.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:footwearpoint/tema/fp_colores.dart';
import 'package:footwearpoint/tema/fp_fluidez.dart';
import 'package:footwearpoint/tema/fp_tema.dart';

void main() {
  test('todas las plataformas usan la transición de iOS (deslizar para regresar)', () {
    final tema = FpTema.claro();
    for (final plataforma in TargetPlatform.values) {
      expect(tema.pageTransitionsTheme.builders[plataforma], isA<CupertinoPageTransitionsBuilder>());
    }
  });

  test('los colores de FootwearPoint no cambian', () {
    expect(FpTema.claro().colorScheme.primary, FpColores.primario);
  });

  testWidgets('las listas rebotan como en iOS', (tester) async {
    late ScrollPhysics fisica;
    await tester.pumpWidget(
      MaterialApp(
        scrollBehavior: const FpScrollBehavior(),
        home: Builder(
          builder: (context) {
            fisica = ScrollConfiguration.of(context).getScrollPhysics(context);
            return const SizedBox.shrink();
          },
        ),
      ),
    );
    expect(fisica, isA<BouncingScrollPhysics>());
  });
}
