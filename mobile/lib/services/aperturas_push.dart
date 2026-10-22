import 'dart:async';

import 'package:firebase_messaging/firebase_messaging.dart';
import 'package:flutter/material.dart';

import '../screens/historial_cambio_screen.dart';
import '../screens/pedido_detalle_screen.dart';

/// El navegador de la app, para abrir una pantalla al tocar un push.
final GlobalKey<NavigatorState> navegadorRaiz = GlobalKey<NavigatorState>();

/// Si los datos de un push son de una solicitud de cambio, su id.
/// El servidor manda entidad_tipo = 'solicitud_cambio' y entidad_id como texto.
int? solicitudCambioDePush(Map<String, dynamic> datos) {
  return _idDe(datos, 'solicitud_cambio');
}

/// Pedido o pago: el servidor manda entidad_tipo = 'pedido'.
int? pedidoDePush(Map<String, dynamic> datos) {
  return _idDe(datos, 'pedido');
}

int? _idDe(Map<String, dynamic> datos, String tipo) {
  if (datos['entidad_tipo'] != tipo) return null;
  final id = datos['entidad_id'];
  if (id is int) return id > 0 ? id : null;
  final numero = int.tryParse('${id ?? ''}');
  return numero != null && numero > 0 ? numero : null;
}

/// Al tocar un push abre el pedido o la solicitud de cambio.
/// Con la app abierta, el aviso se muestra ahí mismo.
class AperturasPush {
  AperturasPush._();

  static StreamSubscription<RemoteMessage>? _suscripcion;
  static StreamSubscription<RemoteMessage>? _enPrimerPlano;
  static bool _inicialRevisado = false;

  static Future<void> escuchar() async {
    try {
      _suscripcion ??= FirebaseMessaging.onMessageOpenedApp.listen((m) => abrir(m.data));
      _enPrimerPlano ??= FirebaseMessaging.onMessage.listen(mostrarEnPrimerPlano);

      if (!_inicialRevisado) {
        _inicialRevisado = true;
        final inicial = await FirebaseMessaging.instance.getInitialMessage();
        if (inicial != null) abrir(inicial.data);
      }
    } catch (e) {
      debugPrint('No se pudo escuchar la apertura de push: $e');
    }
  }

  static void mostrarEnPrimerPlano(RemoteMessage mensaje) {
    final titulo = mensaje.notification?.title ?? 'Aviso';
    final cuerpo = mensaje.notification?.body ?? '';
    mostrarAviso(titulo: titulo, cuerpo: cuerpo, datos: mensaje.data);
  }

  static void mostrarAviso({
    required String titulo,
    required String cuerpo,
    required Map<String, dynamic> datos,
  }) {
    final contexto = navegadorRaiz.currentContext;
    if (contexto == null) return;
    final mensajero = ScaffoldMessenger.maybeOf(contexto);
    if (mensajero == null) return;

    final texto = cuerpo.isEmpty ? titulo : '$titulo. $cuerpo';
    mensajero.hideCurrentSnackBar();
    mensajero.showSnackBar(
      SnackBar(
        content: Text(texto),
        action: SnackBarAction(label: 'Ver', onPressed: () => abrir(datos)),
      ),
    );
  }

  static void abrir(Map<String, dynamic> datos) {
    final navegador = navegadorRaiz.currentState;
    if (navegador == null) return;

    final solicitudId = solicitudCambioDePush(datos);
    if (solicitudId != null) {
      navegador.push(
        MaterialPageRoute<void>(builder: (_) => HistorialCambioScreen(solicitudId: solicitudId)),
      );
      return;
    }

    final pedidoId = pedidoDePush(datos);
    if (pedidoId == null) return;

    navegador.push(
      MaterialPageRoute<void>(builder: (_) => PedidoDetalleScreen(pedidoId: pedidoId)),
    );
  }

  /// Al cerrar sesión.
  static Future<void> detener() async {
    await _suscripcion?.cancel();
    await _enPrimerPlano?.cancel();
    _suscripcion = null;
    _enPrimerPlano = null;
  }
}
