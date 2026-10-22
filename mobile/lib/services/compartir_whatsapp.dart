import 'package:flutter/material.dart';
import 'package:url_launcher/url_launcher.dart';

/// Compartir por WhatsApp con el texto ya escrito (wa.me).
///
/// Mismo formato que App\Support\WhatsApp en el servidor: sin teléfono,
/// WhatsApp deja elegir el chat; los teléfonos de 10 dígitos son de México.
Uri enlaceWhatsApp(String texto, {String? telefono}) {
  final digitos = (telefono ?? '').replaceAll(RegExp(r'\D'), '');
  final numero = digitos.length == 10 ? '52$digitos' : (digitos.length >= 11 && digitos.length <= 15 ? digitos : '');

  return Uri.parse('https://wa.me/$numero?text=${Uri.encodeComponent(texto.trim())}');
}

/// Abre WhatsApp. Si no se puede (no está instalado ni hay navegador), avisa.
Future<void> compartirPorWhatsApp(BuildContext context, String texto, {String? telefono}) async {
  final mensajero = ScaffoldMessenger.of(context);
  final abierto = await launchUrl(enlaceWhatsApp(texto, telefono: telefono), mode: LaunchMode.externalApplication);

  if (!abierto) {
    mensajero.showSnackBar(const SnackBar(content: Text('No se pudo abrir WhatsApp.')));
  }
}

/// El enlace que la app abre al tocarlo.
const hostEnlacesApp = 'https://footwearpoint.app';

String enlaceAbrirPedido(int id) => '$hostEnlacesApp/abrir/pedido/$id';

String enlaceAbrirProducto(int id) => '$hostEnlacesApp/abrir/producto/$id';

/// Agrega el enlace de la app si el texto todavía no lo trae.
String conEnlaceDeApp(String texto, String enlace) {
  if (texto.contains(enlace)) return texto.trim();
  final base = texto.trim();
  return [if (base.isNotEmpty) base, 'Abrir en la app: $enlace'].join('\n');
}

/// Texto para compartir un producto del catálogo.
String textoProductoWhatsApp({
  required String nombre,
  required String modelo,
  required double precio,
  String? url,
  int? productoCampanaId,
}) {
  final lineas = [
    '¡Mira este modelo! 👟',
    '$nombre (modelo $modelo)',
    'Precio: \$${precio.toStringAsFixed(2)}',
    if (url != null && url.isNotEmpty) url,
  ];

  final texto = lineas.join('\n');
  if (productoCampanaId == null) return texto;

  return conEnlaceDeApp(texto, enlaceAbrirProducto(productoCampanaId));
}
