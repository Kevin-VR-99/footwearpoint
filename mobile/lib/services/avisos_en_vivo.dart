import 'dart:async';

import 'package:firebase_messaging/firebase_messaging.dart';
import 'package:flutter/material.dart';

/// Los avisos que llegan MIENTRAS la app está abierta (TG-281).
///
/// Android no enseña solo los avisos de Firebase cuando la app está en
/// primer plano: se los entrega a la app y es ella quien decide qué hacer.
/// Como aquí nadie escuchaba `onMessage`, pasaba lo que se reportó: estando
/// dentro de la app no salía nada, y la campanita no se enteraba hasta que
/// se tocaba.
///
/// Esto no reemplaza al aviso del sistema: ese sigue saliendo solo cuando la
/// app está cerrada o en segundo plano, que es cuando tiene sentido.
class AvisosEnVivo {
  AvisosEnVivo._();

  static final _controlador = StreamController<RemoteMessage>.broadcast();

  static StreamSubscription<RemoteMessage>? _suscripcion;

  /// Cada aviso que llega con la app abierta. Lo escuchan la campanita (para
  /// volver a contar) y el cascarón (para enseñar la franja).
  static Stream<RemoteMessage> get alLlegar => _controlador.stream;

  static Future<void> escuchar() async {
    try {
      _suscripcion ??= FirebaseMessaging.onMessage.listen(_controlador.add);
    } catch (e) {
      // Sin Firebase (pruebas, o un teléfono sin servicios de Google) la app
      // sigue funcionando: solo no hay avisos en vivo.
      debugPrint('No se pudo escuchar los avisos en vivo: $e');
    }
  }

  /// Al cerrar sesión.
  static Future<void> detener() async {
    await _suscripcion?.cancel();
    _suscripcion = null;
  }

  /// Para las pruebas: simula que llegó un aviso.
  @visibleForTesting
  static void simular(RemoteMessage mensaje) => _controlador.add(mensaje);
}
