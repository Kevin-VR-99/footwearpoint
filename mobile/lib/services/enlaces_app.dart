import 'dart:async';

import 'package:app_links/app_links.dart';
import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../providers/auth_provider.dart';
import '../screens/pedido_detalle_screen.dart';
import '../screens/producto_detalle_screen.dart';
import 'aperturas_push.dart';
import 'catalogo_service.dart';

/// A dónde lleva un enlace https://footwearpoint.app/abrir/...
class DestinoEnlace {
  const DestinoEnlace.pedido(this.id) : esProducto = false;
  const DestinoEnlace.producto(this.id) : esProducto = true;

  final int id;
  final bool esProducto;
}

DestinoEnlace? destinoDeEnlace(Uri uri) {
  final host = uri.host.toLowerCase();
  final hostNuestro = host.isEmpty || host == 'footwearpoint.app' || host.endsWith('.footwearpoint.app');
  if (!hostNuestro) return null;

  final partes = uri.pathSegments;
  if (partes.length < 3 || partes[0] != 'abrir') return null;
  final id = int.tryParse(partes[2]);
  if (id == null || id <= 0) return null;
  if (partes[1] == 'pedido') return DestinoEnlace.pedido(id);
  if (partes[1] == 'producto') return DestinoEnlace.producto(id);
  return null;
}

/// Abre el pedido o el producto cuando llega un enlace de WhatsApp.
class EnlacesApp {
  EnlacesApp._();

  static bool Function()? haySesion;
  static Uri? _pendiente;
  static bool _escuchando = false;

  static Future<void> escuchar() async {
    if (_escuchando) return;
    _escuchando = true;

    try {
      final enlaces = AppLinks();
      final inicial = await enlaces.getInitialLink();
      if (inicial != null) recibir(inicial);
      enlaces.uriLinkStream.listen(recibir, onError: (Object e) {
        debugPrint('No se pudo leer el enlace: $e');
      });
    } catch (e) {
      debugPrint('No se pudieron escuchar los enlaces: $e');
    }
  }

  static void recibir(Uri uri) {
    if (destinoDeEnlace(uri) == null) return;
    _pendiente = uri;
    if (haySesion?.call() == true) abrirSiHaySesion();
  }

  static void olvidar() {
    _pendiente = null;
  }

  static void abrirSiHaySesion() {
    final uri = _pendiente;
    if (uri == null) return;
    if (navegadorRaiz.currentState == null) {
      WidgetsBinding.instance.addPostFrameCallback((_) => abrirSiHaySesion());
      return;
    }

    final destino = destinoDeEnlace(uri);
    _pendiente = null;
    if (destino == null) return;
    unawaited(_abrir(destino));
  }

  static Future<void> _abrir(DestinoEnlace destino) async {
    final navegador = navegadorRaiz.currentState;
    final contexto = navegadorRaiz.currentContext;
    if (navegador == null || contexto == null) return;

    if (!destino.esProducto) {
      navegador.push(
        MaterialPageRoute<void>(builder: (_) => PedidoDetalleScreen(pedidoId: destino.id)),
      );
      return;
    }

    try {
      final api = contexto.read<AuthProvider>().api;
      final productos = await CatalogoService(api).obtener();
      final actual = navegadorRaiz.currentContext;
      final nav = navegadorRaiz.currentState;
      if (actual == null || nav == null || !actual.mounted) return;

      final producto = productos.where((p) => p.id == destino.id).firstOrNull;
      if (producto == null) {
        ScaffoldMessenger.maybeOf(actual)?.showSnackBar(
          const SnackBar(content: Text('Ese producto ya no está en el catálogo.')),
        );
        return;
      }
      nav.push(MaterialPageRoute<void>(builder: (_) => ProductoDetalleScreen(producto: producto)));
    } catch (e) {
      debugPrint('No se pudo abrir el producto del enlace: $e');
    }
  }
}
