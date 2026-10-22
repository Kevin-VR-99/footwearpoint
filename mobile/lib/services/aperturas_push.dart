import 'dart:async';

import 'package:firebase_messaging/firebase_messaging.dart';
import 'package:flutter/material.dart';

import '../screens/historial_cambio_screen.dart';

/// El navegador de la app, para abrir una pantalla al tocar un push.
final GlobalKey<NavigatorState> navegadorRaiz = GlobalKey<NavigatorState>();

/// Si los datos de un push son de una solicitud de cambio, su id.
/// El servidor manda entidad_tipo = 'solicitud_cambio' y entidad_id como texto.
int? solicitudCambioDePush(Map<String, dynamic> datos) {
  if (datos['entidad_tipo'] != 'solicitud_cambio') return null;
  final id = datos['entidad_id'];
  if (id is int) return id > 0 ? id : null;
  final numero = int.tryParse('${id ?? ''}');
  return numero != null && numero > 0 ? numero : null;
}

/// Al tocar un push (con la app en segundo plano o cerrada), abre la
/// pantalla que corresponde. Por ahora: el historial de una solicitud de cambio.
class AperturasPush {
  AperturasPush._();

  static StreamSubscription<RemoteMessage>? _suscripcion;
  static bool _inicialRevisado = false;

  static Future<void> escuchar() async {
    try {
      _suscripcion ??= FirebaseMessaging.onMessageOpenedApp.listen((m) => abrir(m.data));

      if (!_inicialRevisado) {
        _inicialRevisado = true;
        final inicial = await FirebaseMessaging.instance.getInitialMessage();
        if (inicial != null) abrir(inicial.data);
      }
    } catch (e) {
      debugPrint('No se pudo escuchar la apertura de push: $e');
    }
  }

  static void abrir(Map<String, dynamic> datos) {
    final solicitudId = solicitudCambioDePush(datos);
    final navegador = navegadorRaiz.currentState;
    if (solicitudId == null || navegador == null) return;

    navegador.push(
      MaterialPageRoute<void>(builder: (_) => HistorialCambioScreen(solicitudId: solicitudId)),
    );
  }

  /// Al cerrar sesión.
  static Future<void> detener() async {
    await _suscripcion?.cancel();
    _suscripcion = null;
  }
}
