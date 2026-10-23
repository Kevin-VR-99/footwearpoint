import 'package:flutter/foundation.dart';

/// La pestaña registra cómo volver a pedir sus datos. El cascarón lo llama
/// al entrar otra vez: IndexedStack no reconstruye la pantalla, y si no se
/// vuelve a pedir, el inicio se queda con vales y saldos viejos.
class RecargaDePestana {
  VoidCallback? _accion;

  void registrar(VoidCallback accion) => _accion = accion;

  void llamar() => _accion?.call();
}
