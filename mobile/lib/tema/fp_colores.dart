import 'package:flutter/material.dart';

/// La paleta de FootwearPoint, la misma del panel web (TG-165).
///
/// Sale de resources/css/app.css (bloque @theme, "Paleta FootwearPoint",
/// sección 1.4 del Plan de Tareas). Los nombres siguen a los de la web:
/// --color-fp-sidebar -> [FpColores.sidebar], etc. Si allá cambia un color,
/// se cambia aquí también.
abstract final class FpColores {
  // --- Marca ---------------------------------------------------------------

  /// Azul marino del menú lateral (--color-fp-sidebar).
  static const sidebar = Color(0xFF111E38);

  /// Azul del degradado del menú (--color-fp-accent).
  static const acento = Color(0xFF1E2F52);

  /// Azul de botones y enlaces (--color-fp-primary).
  static const primario = Color(0xFF2563EB);

  /// Rojo de avisos y detalles (--color-fp-danger).
  static const peligro = Color(0xFFDC2626);

  /// Fondo rojo muy suave (--color-fp-danger-soft).
  static const peligroSuave = Color(0xFFFEF2F2);

  /// Fondo de las páginas (--color-fp-page).
  static const pagina = Color(0xFFF5F6FA);

  /// Texto secundario (--color-fp-text-muted).
  static const textoTenue = Color(0xFF64748B);

  // --- Grises de Tailwind que usa la web (slate) ---------------------------

  static const texto = Color(0xFF1E293B); // text-slate-800
  static const borde = Color(0xFFE2E8F0); // border-slate-200
  static const bordeFuerte = Color(0xFFCBD5E1); // border-slate-300
  static const fondoSuave = Color(0xFFF1F5F9); // bg-slate-100

  // --- Insignias: fondo claro + texto oscuro (--color-fp-badge-*) ----------

  static const insigniaNeutralFondo = Color(0xFFE2E8F0);
  static const insigniaNeutralTexto = Color(0xFF334155);
  static const insigniaInfoFondo = Color(0xFFDBEAFE);
  static const insigniaInfoTexto = Color(0xFF1E3A8A);
  static const insigniaExitoFondo = Color(0xFFD1FAE5);
  static const insigniaExitoTexto = Color(0xFF065F46);
  static const insigniaAvisoFondo = Color(0xFFFFEDD5);
  static const insigniaAvisoTexto = Color(0xFF9A3412);
  static const insigniaPeligroFondo = Color(0xFFFEE2E2);
  static const insigniaPeligroTexto = Color(0xFF991B1B);

  /// La línea delgada de arriba del encabezado web:
  /// from-fp-primary via-fp-sidebar to-fp-danger.
  static const degradadoMarca = LinearGradient(colors: [primario, sidebar, peligro]);

  /// El fondo del menú lateral: from-fp-sidebar via-fp-sidebar to-fp-accent.
  static const degradadoMenu = LinearGradient(
    begin: Alignment.topCenter,
    end: Alignment.bottomCenter,
    colors: [sidebar, sidebar, acento],
  );
}
