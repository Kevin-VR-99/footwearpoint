import 'package:flutter/cupertino.dart';
import 'package:flutter/material.dart';

/// Sensación tipo iOS 27 (Liquid Glass) sin cambiar colores ni widgets de
/// Material: rebote al llegar al borde de las listas en todas las
/// plataformas, como en iPhone.
class FpScrollBehavior extends MaterialScrollBehavior {
  const FpScrollBehavior();

  @override
  ScrollPhysics getScrollPhysics(BuildContext context) =>
      const BouncingScrollPhysics(parent: AlwaysScrollableScrollPhysics());
}

/// Transiciones de iOS (con deslizar desde el borde para regresar) en todas
/// las plataformas.
const fpTransiciones = PageTransitionsTheme(
  builders: {
    TargetPlatform.android: CupertinoPageTransitionsBuilder(),
    TargetPlatform.iOS: CupertinoPageTransitionsBuilder(),
    TargetPlatform.fuchsia: CupertinoPageTransitionsBuilder(),
    TargetPlatform.linux: CupertinoPageTransitionsBuilder(),
    TargetPlatform.macOS: CupertinoPageTransitionsBuilder(),
    TargetPlatform.windows: CupertinoPageTransitionsBuilder(),
  },
);

/// Curva con un poco de resorte, para animaciones cortas.
const fpCurvaResorte = Curves.easeOutBack;

/// Duración corta de las animaciones de la app.
const fpDuracionCorta = Duration(milliseconds: 220);
