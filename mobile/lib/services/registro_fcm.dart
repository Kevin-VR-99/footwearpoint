import 'dart:async';

import 'package:flutter/foundation.dart';

import 'api_service.dart';

/// Manda el token de Firebase de este celular a POST /api/dispositivos-fcm
/// (E16-03), con reintentos.
///
/// Antes se intentaba una sola vez: si en ese momento no había red o el
/// servidor tardaba, el celular se quedaba sin registrar hasta el siguiente
/// inicio de sesión y no le llegaban los push. Y la renovación del token
/// (onTokenRefresh) no atrapaba el error ni reintentaba.
///
/// Reglas:
/// - Error de red / tiempo agotado / 5xx / 429: se reintenta con espera
///   creciente (2 s, 4 s, 8 s, 16 s, 32 s).
/// - 4xx (401 sesión vencida, 422 token inválido): no tiene caso reintentar.
/// - Si llega un token nuevo mientras se reintenta el viejo, gana el nuevo.
/// - [detener] (al cerrar sesión) corta cualquier reintento pendiente.
class RegistroFcm {
  RegistroFcm({
    required this.enviar,
    this.intentosMaximos = 5,
    this.esperaInicial = const Duration(seconds: 2),
    Future<void> Function(Duration)? esperar,
  }) : _esperar = esperar ?? Future<void>.delayed;

  /// La llamada al servidor (POST dispositivos-fcm).
  final Future<void> Function(String token) enviar;
  final Future<void> Function(Duration) _esperar;
  final int intentosMaximos;
  final Duration esperaInicial;

  /// Sube cada que se pide un registro nuevo o se detiene; un intento con
  /// un número viejo ya no sigue.
  int _generacion = 0;

  /// El último token que el servidor sí guardó.
  String? ultimoRegistrado;

  /// Devuelve true si el servidor guardó el token.
  Future<bool> registrar(String token) async {
    final miGeneracion = ++_generacion;
    var espera = esperaInicial;

    for (var intento = 1; intento <= intentosMaximos; intento++) {
      if (miGeneracion != _generacion) return false;
      try {
        await enviar(token);
        if (miGeneracion != _generacion) return false;
        ultimoRegistrado = token;
        return true;
      } on ApiException catch (e) {
        final codigo = e.codigoHttp;
        final reintentable = codigo == null || codigo >= 500 || codigo == 429;
        if (!reintentable) {
          debugPrint('FCM: el servidor rechazó el token ($codigo).');
          return false;
        }
        debugPrint('FCM: intento $intento falló: ${e.mensaje}');
      } catch (e) {
        debugPrint('FCM: intento $intento falló: $e');
      }

      if (intento < intentosMaximos) {
        await _esperar(espera);
        espera *= 2;
      }
    }
    return false;
  }

  /// Igual que [registrar], pero también reintenta pedirle el token a
  /// Firebase: sin red, getToken() falla (SERVICE_NOT_AVAILABLE) y antes ahí
  /// se acababa todo.
  Future<bool> registrarDesde(Future<String?> Function() obtenerToken) async {
    final miGeneracion = _generacion;
    var espera = esperaInicial;
    for (var intento = 1; intento <= intentosMaximos; intento++) {
      if (miGeneracion != _generacion) return false;
      try {
        final token = await obtenerToken();
        if (token == null || token.isEmpty) return false;
        if (miGeneracion != _generacion) return false;
        return await registrar(token);
      } catch (e) {
        debugPrint('FCM: no se obtuvo el token (intento $intento): $e');
      }
      if (intento < intentosMaximos) {
        await _esperar(espera);
        espera *= 2;
      }
    }
    return false;
  }

  /// Corta los reintentos pendientes (al cerrar sesión).
  void detener() {
    _generacion++;
    ultimoRegistrado = null;
  }
}
