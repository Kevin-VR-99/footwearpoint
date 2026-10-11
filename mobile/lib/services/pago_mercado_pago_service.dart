import 'package:url_launcher/url_launcher.dart';

import '../models/pedido_resumen.dart';
import 'api_service.dart';

/// El enlace de Checkout Pro que prepara el servidor.
class EnlacePagoMercadoPago {
  const EnlacePagoMercadoPago({required this.initPoint, required this.monto, required this.reutilizado});

  final Uri initPoint;
  final double monto;

  /// true si el servidor regresó el enlace que ya estaba vivo.
  final bool reutilizado;
}

/// Lo que contestó el servidor al preguntar a Mercado Pago si ya se pagó.
class ResultadoPagoMercadoPago {
  const ResultadoPagoMercadoPago({required this.resultado, required this.mensaje, this.pedido});

  /// 'aplicado', 'pendiente', 'rechazado', 'vencido' o 'no_cuadra'.
  final String resultado;
  final String mensaje;
  final PedidoResumen? pedido;

  bool get aplicado => resultado == 'aplicado';

  /// Mercado Pago tiene un pago que no coincide: no se debe volver a pagar.
  bool get noCuadra => resultado == 'no_cuadra';

  /// Rechazado o vencido: se puede pagar otra vez.
  bool get sePuedeReintentar => resultado == 'rechazado' || resultado == 'vencido';
}

/// Abre una dirección fuera de la app. Se puede cambiar en pruebas.
typedef AbrirEnlace = Future<bool> Function(Uri enlace);

/// En el navegador o en la app de Mercado Pago, no dentro de la app (TG-268).
Future<bool> abrirEnNavegador(Uri enlace) => launchUrl(enlace, mode: LaunchMode.externalApplication);

/// Pagar con Mercado Pago (Checkout Pro) desde la app (TG-268, E11-01, E8-02).
///
/// El servidor decide el monto y la cuenta de la distribuidora; la app solo
/// abre el enlace y, al regresar, pregunta cómo quedó.
class PagoMercadoPagoService {
  const PagoMercadoPagoService(this._api);

  final ApiService _api;

  Future<EnlacePagoMercadoPago> crear(int pedidoId, TipoPagoMercadoPago tipo) async {
    final respuesta = await _api.post('pedidos/$pedidoId/${tipo.ruta}/mercado-pago');
    final datos = respuesta['data'] as Map<String, dynamic>;

    return EnlacePagoMercadoPago(
      initPoint: Uri.parse(datos['init_point'] as String),
      monto: (datos['monto'] as num?)?.toDouble() ?? 0,
      reutilizado: datos['reutilizado'] == true,
    );
  }

  Future<ResultadoPagoMercadoPago> verificar(int pedidoId, TipoPagoMercadoPago tipo) async {
    final respuesta = await _api.post('pedidos/$pedidoId/${tipo.ruta}/mercado-pago/verificar');
    final datos = respuesta['data'];

    return ResultadoPagoMercadoPago(
      resultado: respuesta['resultado'] as String? ?? 'pendiente',
      mensaje: respuesta['message'] as String? ?? '',
      pedido: datos is Map<String, dynamic> ? PedidoResumen.desdeJson(datos) : null,
    );
  }
}
