import 'package:flutter/material.dart';

import 'fp_colores.dart';

/// El tema de toda la app, con el estilo del panel web (TG-165).
///
/// Solo cambia cómo se ve: fondo gris claro, barra de arriba blanca, tarjetas
/// blancas muy redondeadas con borde suave (rounded-2xl border-slate-200
/// shadow-sm), campos y botones redondeados, y el azul de la web como color
/// principal. Antes era el tema por defecto de Flutter en café.
abstract final class FpTema {
  static const _radioTarjeta = 16.0; // rounded-2xl
  static const _radioControl = 12.0; // rounded-xl

  static ThemeData claro() {
    final esquema = ColorScheme.fromSeed(
      seedColor: FpColores.primario,
      brightness: Brightness.light,
    ).copyWith(
      primary: FpColores.primario,
      onPrimary: Colors.white,
      primaryContainer: FpColores.insigniaInfoFondo,
      onPrimaryContainer: FpColores.insigniaInfoTexto,
      secondary: FpColores.acento,
      onSecondary: Colors.white,
      secondaryContainer: FpColores.fondoSuave,
      onSecondaryContainer: FpColores.sidebar,
      tertiary: FpColores.peligro,
      error: FpColores.peligro,
      onError: Colors.white,
      errorContainer: FpColores.peligroSuave,
      onErrorContainer: FpColores.insigniaPeligroTexto,
      surface: Colors.white,
      onSurface: FpColores.texto,
      onSurfaceVariant: FpColores.textoTenue,
      surfaceContainerLowest: Colors.white,
      surfaceContainerLow: FpColores.pagina,
      surfaceContainer: FpColores.pagina,
      surfaceContainerHigh: FpColores.fondoSuave,
      surfaceContainerHighest: FpColores.fondoSuave,
      outline: FpColores.bordeFuerte,
      outlineVariant: FpColores.borde,
      surfaceTint: Colors.transparent,
    );

    final base = ThemeData(useMaterial3: true, colorScheme: esquema);

    final bordeCampo = OutlineInputBorder(
      borderRadius: BorderRadius.circular(_radioControl),
      borderSide: const BorderSide(color: FpColores.bordeFuerte),
    );
    final formaControl = RoundedRectangleBorder(borderRadius: BorderRadius.circular(_radioControl));

    return base.copyWith(
      scaffoldBackgroundColor: FpColores.pagina,
      textTheme: base.textTheme.apply(
        bodyColor: FpColores.texto,
        // Títulos en el azul marino de la web (text-fp-sidebar).
        displayColor: FpColores.sidebar,
      ),
      appBarTheme: const AppBarTheme(
        backgroundColor: Colors.white,
        foregroundColor: FpColores.sidebar,
        elevation: 0,
        scrolledUnderElevation: 1,
        shadowColor: Color(0x1A000000),
        surfaceTintColor: Colors.transparent,
        centerTitle: false,
        titleTextStyle: TextStyle(
          color: FpColores.sidebar,
          fontSize: 18,
          fontWeight: FontWeight.w600,
          letterSpacing: -0.2,
        ),
        shape: Border(bottom: BorderSide(color: FpColores.borde)),
      ),
      cardTheme: CardThemeData(
        color: Colors.white,
        elevation: 1,
        shadowColor: const Color(0x14000000),
        surfaceTintColor: Colors.transparent,
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(_radioTarjeta),
          side: const BorderSide(color: FpColores.borde),
        ),
      ),
      dividerTheme: const DividerThemeData(color: FpColores.borde, thickness: 1),
      inputDecorationTheme: InputDecorationTheme(
        filled: true,
        fillColor: Colors.white,
        contentPadding: const EdgeInsets.symmetric(horizontal: 14, vertical: 14),
        border: bordeCampo,
        enabledBorder: bordeCampo,
        focusedBorder: bordeCampo.copyWith(
          borderSide: const BorderSide(color: FpColores.primario, width: 1.6),
        ),
        errorBorder: bordeCampo.copyWith(borderSide: const BorderSide(color: FpColores.peligro)),
        focusedErrorBorder: bordeCampo.copyWith(
          borderSide: const BorderSide(color: FpColores.peligro, width: 1.6),
        ),
        disabledBorder: bordeCampo.copyWith(borderSide: const BorderSide(color: FpColores.borde)),
        labelStyle: const TextStyle(color: FpColores.textoTenue),
        floatingLabelStyle: const TextStyle(color: FpColores.primario, fontWeight: FontWeight.w500),
        prefixIconColor: FpColores.textoTenue,
        suffixIconColor: FpColores.textoTenue,
      ),
      filledButtonTheme: FilledButtonThemeData(
        style: FilledButton.styleFrom(
          minimumSize: const Size(64, 48),
          shape: formaControl,
          textStyle: const TextStyle(fontSize: 15, fontWeight: FontWeight.w600),
        ),
      ),
      outlinedButtonTheme: OutlinedButtonThemeData(
        style: OutlinedButton.styleFrom(
          minimumSize: const Size(64, 48),
          shape: formaControl,
          foregroundColor: FpColores.sidebar,
          backgroundColor: Colors.white,
          side: const BorderSide(color: FpColores.bordeFuerte),
          textStyle: const TextStyle(fontSize: 15, fontWeight: FontWeight.w600),
        ),
      ),
      textButtonTheme: TextButtonThemeData(
        style: TextButton.styleFrom(
          foregroundColor: FpColores.primario,
          shape: formaControl,
          textStyle: const TextStyle(fontWeight: FontWeight.w600),
        ),
      ),
      iconButtonTheme: IconButtonThemeData(
        style: IconButton.styleFrom(shape: formaControl),
      ),
      floatingActionButtonTheme: FloatingActionButtonThemeData(
        backgroundColor: FpColores.primario,
        foregroundColor: Colors.white,
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(_radioTarjeta)),
      ),
      chipTheme: base.chipTheme.copyWith(
        shape: const StadiumBorder(side: BorderSide(color: FpColores.borde)),
        backgroundColor: Colors.white,
        selectedColor: FpColores.insigniaInfoFondo,
        labelStyle: const TextStyle(color: FpColores.texto, fontWeight: FontWeight.w500),
      ),
      listTileTheme: const ListTileThemeData(
        iconColor: FpColores.textoTenue,
        titleTextStyle: TextStyle(color: FpColores.texto, fontSize: 15, fontWeight: FontWeight.w500),
        subtitleTextStyle: TextStyle(color: FpColores.textoTenue, fontSize: 13),
      ),
      badgeTheme: const BadgeThemeData(backgroundColor: FpColores.peligro, textColor: Colors.white),
      snackBarTheme: SnackBarThemeData(
        behavior: SnackBarBehavior.floating,
        backgroundColor: FpColores.sidebar,
        contentTextStyle: const TextStyle(color: Colors.white),
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(_radioControl)),
      ),
      dialogTheme: DialogThemeData(
        backgroundColor: Colors.white,
        surfaceTintColor: Colors.transparent,
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(20)),
        titleTextStyle: const TextStyle(color: FpColores.sidebar, fontSize: 20, fontWeight: FontWeight.w600),
      ),
      bottomSheetTheme: const BottomSheetThemeData(
        backgroundColor: Colors.white,
        surfaceTintColor: Colors.transparent,
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.vertical(top: Radius.circular(20))),
      ),
      progressIndicatorTheme: const ProgressIndicatorThemeData(
        color: FpColores.primario,
        linearTrackColor: FpColores.fondoSuave,
      ),
    );
  }
}
