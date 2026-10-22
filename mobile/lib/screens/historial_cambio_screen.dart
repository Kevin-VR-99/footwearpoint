import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../models/solicitud_cambio.dart';
import '../providers/auth_provider.dart';
import '../services/api_service.dart';
import '../services/cambio_service.dart';
import '../tema/fp_colores.dart';
import '../widgets/foto_calzado.dart';
import '../widgets/fp_componentes.dart';

/// Historial de una solicitud de cambio: cada paso (fecha y hora de la
/// Ciudad de México, estado, quién y la nota) como línea de tiempo animada.
///
/// Se llega desde "Mis cambios", desde el pedido y al tocar un aviso o un
/// push de cambio de estado.
class HistorialCambioScreen extends StatefulWidget {
  const HistorialCambioScreen({super.key, required this.solicitudId});

  final int solicitudId;

  @override
  State<HistorialCambioScreen> createState() => _HistorialCambioScreenState();
}

class _HistorialCambioScreenState extends State<HistorialCambioScreen> with SingleTickerProviderStateMixin {
  late final CambioService _servicio = CambioService(context.read<AuthProvider>().api);
  late final AnimationController _entrada = AnimationController(vsync: this);

  SolicitudCambio? _solicitud;
  String? _error;
  bool _cargando = true;

  @override
  void initState() {
    super.initState();
    _cargar();
  }

  @override
  void dispose() {
    _entrada.dispose();
    super.dispose();
  }

  Future<void> _cargar() async {
    setState(() {
      _cargando = true;
      _error = null;
    });
    try {
      final solicitud = await _servicio.detalle(widget.solicitudId);
      if (!mounted) return;
      setState(() {
        _solicitud = solicitud;
        _cargando = false;
      });
      // Un poco más de tiempo mientras más pasos haya, con tope.
      final pasos = solicitud.pasos.length.clamp(1, 8);
      _entrada.duration = Duration(milliseconds: 350 + 160 * pasos);
      if (MediaQuery.of(context).disableAnimations) {
        _entrada.value = 1;
      } else {
        _entrada.forward(from: 0);
      }
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _error = e.codigoHttp == 404 ? 'No encontramos esta solicitud de cambio.' : e.mensaje;
        _cargando = false;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Historial del cambio'), bottom: const FpBordeMarca()),
      body: SafeArea(child: _contenido()),
    );
  }

  Widget _contenido() {
    if (_cargando) return const Center(child: CircularProgressIndicator());

    final solicitud = _solicitud;
    if (solicitud == null) {
      return Center(
        child: FpEstadoVacio(
          error: true,
          icono: Icons.cloud_off_outlined,
          titulo: _error ?? 'No se pudo cargar el historial.',
          accion: FilledButton.icon(
            onPressed: _cargar,
            icon: const Icon(Icons.refresh),
            label: const Text('Reintentar'),
          ),
        ),
      );
    }

    final pasos = solicitud.pasos;

    return RefreshIndicator(
      onRefresh: _cargar,
      child: ListView(
        padding: const EdgeInsets.fromLTRB(16, 16, 16, 32),
        children: [
          _Encabezado(solicitud: solicitud),
          const SizedBox(height: 20),
          const FpTituloSeccion('Línea de tiempo'),
          if (pasos.isEmpty)
            const Padding(
              padding: EdgeInsets.symmetric(vertical: 16),
              child: Text('Todavía no hay pasos registrados.', style: TextStyle(color: FpColores.textoTenue)),
            ),
          for (var i = 0; i < pasos.length; i++)
            _PasoAnimado(
              animacion: _entrada,
              indice: i,
              total: pasos.length,
              paso: pasos[i],
              esUltimo: i == pasos.length - 1,
              enCurso: i == pasos.length - 1 && !solicitud.terminada,
            ),
          const SizedBox(height: 12),
          const Text(
            'Horas en tiempo de la Ciudad de México. El cambio se entrega como vale; no hay devoluciones en efectivo.',
            style: TextStyle(color: FpColores.textoTenue, fontSize: 12),
          ),
        ],
      ),
    );
  }
}

class _Encabezado extends StatelessWidget {
  const _Encabezado({required this.solicitud});

  final SolicitudCambio solicitud;

  @override
  Widget build(BuildContext context) {
    final detalle = [
      if (solicitud.talla != null && solicitud.talla!.isNotEmpty) 'Talla ${solicitud.talla}',
      if (solicitud.color != null && solicitud.color!.isNotEmpty) solicitud.color!,
    ].join(' · ');
    final (fondo, texto) = _coloresEstado(solicitud.estado);

    return FpTarjeta(
      child: Row(
        children: [
          FotoCalzado(url: solicitud.imagen, nombre: solicitud.productoNombre ?? 'Producto', tamano: 44),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  solicitud.productoNombre ?? 'Solicitud de cambio #${solicitud.id}',
                  style: const TextStyle(fontWeight: FontWeight.w600, color: FpColores.texto, fontSize: 15),
                ),
                if (detalle.isNotEmpty)
                  Text(detalle, style: const TextStyle(color: FpColores.textoTenue, fontSize: 13)),
                const SizedBox(height: 6),
                AnimatedSwitcher(
                  duration: const Duration(milliseconds: 300),
                  child: Container(
                    key: ValueKey(solicitud.estado),
                    padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 3),
                    decoration: BoxDecoration(color: fondo, borderRadius: BorderRadius.circular(999)),
                    child: Text(solicitud.estadoEtiqueta,
                        style: TextStyle(color: texto, fontSize: 12, fontWeight: FontWeight.w600)),
                  ),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

/// Un paso que entra deslizándose y aparece, uno tras otro.
class _PasoAnimado extends StatelessWidget {
  const _PasoAnimado({
    required this.animacion,
    required this.indice,
    required this.total,
    required this.paso,
    required this.esUltimo,
    required this.enCurso,
  });

  final Animation<double> animacion;
  final int indice;
  final int total;
  final PasoCambio paso;
  final bool esUltimo;
  final bool enCurso;

  @override
  Widget build(BuildContext context) {
    final inicio = total <= 1 ? 0.0 : (indice / total) * 0.6;
    final curva = CurvedAnimation(
      parent: animacion,
      curve: Interval(inicio, (inicio + 0.4).clamp(0.0, 1.0), curve: Curves.easeOutCubic),
    );

    return FadeTransition(
      opacity: curva,
      child: SlideTransition(
        position: Tween<Offset>(begin: const Offset(0.15, 0), end: Offset.zero).animate(curva),
        child: _Paso(paso: paso, esUltimo: esUltimo, enCurso: enCurso, linea: curva),
      ),
    );
  }
}

class _Paso extends StatelessWidget {
  const _Paso({required this.paso, required this.esUltimo, required this.enCurso, required this.linea});

  final PasoCambio paso;
  final bool esUltimo;
  final bool enCurso;
  final Animation<double> linea;

  @override
  Widget build(BuildContext context) {
    final (fondo, color) = _coloresEstado(paso.estado);

    return IntrinsicHeight(
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          SizedBox(
            width: 36,
            child: Column(
              children: [
                _Punto(color: color, fondo: fondo, icono: _iconoEstado(paso.estado), latiendo: enCurso),
                if (!esUltimo)
                  Expanded(
                    // La línea "se dibuja" hacia abajo.
                    child: SizeTransition(
                      sizeFactor: linea,
                      alignment: Alignment.topCenter,
                      child: Center(child: Container(width: 2, color: FpColores.borde)),
                    ),
                  ),
              ],
            ),
          ),
          const SizedBox(width: 10),
          Expanded(
            child: Padding(
              padding: EdgeInsets.only(bottom: esUltimo ? 0 : 18, top: 4),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(paso.etiqueta,
                      style: TextStyle(fontWeight: FontWeight.w600, color: color, fontSize: 15)),
                  const SizedBox(height: 2),
                  Row(
                    children: [
                      const Icon(Icons.schedule, size: 14, color: FpColores.textoTenue),
                      const SizedBox(width: 4),
                      Text(paso.fechaCdmx, style: const TextStyle(color: FpColores.textoTenue, fontSize: 12)),
                    ],
                  ),
                  if (paso.quien != null && paso.quien!.isNotEmpty) ...[
                    const SizedBox(height: 2),
                    Row(
                      children: [
                        const Icon(Icons.person_outline, size: 14, color: FpColores.textoTenue),
                        const SizedBox(width: 4),
                        Flexible(
                          child: Text(paso.quien!, style: const TextStyle(color: FpColores.textoTenue, fontSize: 12)),
                        ),
                      ],
                    ),
                  ],
                  if (paso.nota != null && paso.nota!.trim().isNotEmpty) ...[
                    const SizedBox(height: 6),
                    Container(
                      width: double.infinity,
                      padding: const EdgeInsets.all(10),
                      decoration: BoxDecoration(
                        color: FpColores.fondoSuave,
                        borderRadius: BorderRadius.circular(10),
                      ),
                      child: Text(paso.nota!, style: const TextStyle(color: FpColores.texto, fontSize: 13)),
                    ),
                  ],
                ],
              ),
            ),
          ),
        ],
      ),
    );
  }
}

/// El círculo del paso. El paso en curso "late" suavemente.
class _Punto extends StatefulWidget {
  const _Punto({required this.color, required this.fondo, required this.icono, required this.latiendo});

  final Color color;
  final Color fondo;
  final IconData icono;
  final bool latiendo;

  @override
  State<_Punto> createState() => _PuntoState();
}

class _PuntoState extends State<_Punto> with SingleTickerProviderStateMixin {
  late final AnimationController _latido =
      AnimationController(vsync: this, duration: const Duration(milliseconds: 1400));

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    if (widget.latiendo && !MediaQuery.of(context).disableAnimations) {
      _latido.repeat(reverse: true);
    } else {
      _latido.stop();
    }
  }

  @override
  void dispose() {
    _latido.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final circulo = Container(
      width: 32,
      height: 32,
      decoration: BoxDecoration(
        color: widget.fondo,
        shape: BoxShape.circle,
        border: Border.all(color: widget.color, width: 2),
      ),
      child: Icon(widget.icono, size: 16, color: widget.color),
    );

    if (!widget.latiendo) return circulo;

    return ScaleTransition(
      scale: Tween<double>(begin: 1, end: 1.12).animate(CurvedAnimation(parent: _latido, curve: Curves.easeInOut)),
      child: circulo,
    );
  }
}

(Color, Color) _coloresEstado(String estado) => switch (estado) {
      'rechazada' => (FpColores.insigniaPeligroFondo, FpColores.insigniaPeligroTexto),
      'autorizada' || 'aceptada_fabrica' || 'vale_emitido' => (FpColores.insigniaExitoFondo, FpColores.insigniaExitoTexto),
      'enviada_fabrica' => (FpColores.insigniaAvisoFondo, FpColores.insigniaAvisoTexto),
      'cerrada' => (FpColores.insigniaNeutralFondo, FpColores.insigniaNeutralTexto),
      _ => (FpColores.insigniaInfoFondo, FpColores.insigniaInfoTexto),
    };

IconData _iconoEstado(String estado) => switch (estado) {
      'solicitada' => Icons.edit_note,
      'autorizada' => Icons.check,
      'rechazada' => Icons.close,
      'enviada_fabrica' => Icons.local_shipping_outlined,
      'aceptada_fabrica' => Icons.factory_outlined,
      'vale_emitido' => Icons.confirmation_number_outlined,
      'cerrada' => Icons.lock_outline,
      _ => Icons.circle_outlined,
    };
