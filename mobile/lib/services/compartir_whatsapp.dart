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

/// Texto para compartir un producto del catálogo.
String textoProductoWhatsApp({
  required String nombre,
  required String modelo,
  required double precio,
  String? url,
}) {
  final lineas = [
    '¡Mira este modelo! 👟',
    '$nombre (modelo $modelo)',
    'Precio: \$${precio.toStringAsFixed(2)}',
    if (url != null && url.isNotEmpty) url,
  ];

  return lineas.join('\n');
}
