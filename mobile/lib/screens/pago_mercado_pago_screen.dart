import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../models/pedido_resumen.dart';
import '../providers/auth_provider.dart';
import '../services/api_service.dart';
import '../services/pago_mercado_pago_service.dart';
import '../tema/fp_colores.dart';
import '../widgets/fp_componentes.dart';
import 'producto_detalle_screen.dart' show formatoPrecio;

/// Pagar con Mercado Pago (TG-268, E11-01; anticipo de E8-02 / TG-118).
///
/// Abre Checkout Pro fuera de la app. Al regresar a la app pregunta solo al
/// servidor cómo quedó el pago; también hay un botón "Verificar pago". No hay
/// enlace de regreso directo a la app: si la persona no regresa, el aviso de
/// Mercado Pago al servidor aplica el pago de todos modos.
///
/// Regresa true al cerrarse si algo cambió y hay que recargar el pedido.
class PagoMercadoPagoScreen extends StatefulWidget {
  const PagoMercadoPagoScreen({
    super.key,
    required this.pedido,
    required this.tipo,
    this.verificarAlAbrir = false,
    this.abrirEnlace,
  });

  final PedidoResumen pedido;
  final TipoPagoMercadoPago tipo;

  /// true cuando se abre desde "Verificar pago".
  final bool verificarAlAbrir;

  final AbrirEnlace? abrirEnlace;

  /// Lo que usan todas las pantallas para abrir el enlace; las pruebas lo cambian.
  static AbrirEnlace abrirEnlacePorDefecto = abrirEnNavegador;

  @override
  State<PagoMercadoPagoScreen> createState() => _PagoMercadoPagoScreenState();
}

class _PagoMercadoPagoScreenState extends State<PagoMercadoPagoScreen> with WidgetsBindingObserver {
  late final _pagos = PagoMercadoPagoService(context.read<AuthProvider>().api);

  bool _trabajando = false;

  /// Ya se abrió Mercado Pago: al volver a la app se verifica solo.
  bool _esperandoRegreso = false;

  /// La app sí pasó a segundo plano. Sin esto, un resume de adorno
  /// verificaría el pago antes de que la persona terminara.
  bool _salioASegundoPlano = false;

  /// Volvió mientras [abrir] todavía no terminaba. Se verifica al terminar.
  bool _verificarAlTerminarAbrir = false;

  /// Hay un pago que confirmar (recién abierto o pendiente de antes).
  late bool _porVerificar = widget.verificarAlAbrir;

  ResultadoPagoMercadoPago? _resultado;
  String? _error;
  bool _huboCambios = false;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    if (widget.verificarAlAbrir) {
      WidgetsBinding.instance.addPostFrameCallback((_) => _verificar());
    }
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState estado) {
    final enFondo = estado == AppLifecycleState.paused || estado == AppLifecycleState.hidden;
    if (enFondo && _esperandoRegreso) {
      _salioASegundoPlano = true;
      return;
    }

    if (estado != AppLifecycleState.resumed || !_esperandoRegreso || !_salioASegundoPlano) return;

    _salioASegundoPlano = false;
    _esperandoRegreso = false;
    if (_trabajando) {
      _verificarAlTerminarAbrir = true;
      return;
    }
    _verificar();
  }

  bool get _pagado => _resultado?.aplicado ?? false;
  bool get _noCuadra => _resultado?.noCuadra ?? false;

  Future<void> _pagar() async {
    setState(() {
      _trabajando = true;
      _error = null;
    });

    try {
      final enlace = await _pagos.crear(widget.pedido.id, widget.tipo);
      _huboCambios = true;
      final abrir = widget.abrirEnlace ?? PagoMercadoPagoScreen.abrirEnlacePorDefecto;
      // Antes de esperar: si la persona vuelve mientras el navegador
      // todavía no suelta esta llamada, el resume no se pierde.
      _esperandoRegreso = true;
      _salioASegundoPlano = false;
      _verificarAlTerminarAbrir = false;
      final abierto = await abrir(enlace.initPoint);
      if (!mounted) return;
      final verificarYa = abierto && _verificarAlTerminarAbrir;
      _verificarAlTerminarAbrir = false;
      setState(() {
        _trabajando = false;
        _porVerificar = true;
        _resultado = null;
        if (!abierto) {
          _esperandoRegreso = false;
          _error = 'No se pudo abrir Mercado Pago. Revisa que tengas un navegador instalado.';
        }
      });
      if (verificarYa) await _verificar();
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _trabajando = false;
        _error = e.mensaje;
      });
    }
  }

  Future<void> _verificar() async {
    setState(() {
      _trabajando = true;
      _error = null;
    });

    try {
      final resultado = await _pagos.verificar(widget.pedido.id, widget.tipo);
      if (!mounted) return;
      setState(() {
        _trabajando = false;
        _resultado = resultado;
        _huboCambios = true;
        // Pendiente: se puede volver a verificar. Lo demás ya es definitivo.
        _porVerificar = resultado.resultado == 'pendiente';
      });
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _trabajando = false;
        _error = e.mensaje;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    final tema = Theme.of(context);
    final pedido = _resultado?.pedido ?? widget.pedido;
    final monto = widget.pedido.montoMercadoPago(widget.tipo);

    return PopScope<bool>(
      canPop: false,
      onPopInvokedWithResult: (salio, _) {
        if (!salio) Navigator.of(context).pop(_huboCambios);
      },
      child: Scaffold(
        appBar: AppBar(title: const Text('Pago con tarjeta'), bottom: const FpBordeMarca()),
        body: SafeArea(
          child: ListView(
            padding: const EdgeInsets.all(16),
            children: [
              FpTarjeta(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text('Pedido ${pedido.folio}', style: tema.textTheme.bodyMedium?.copyWith(color: FpColores.textoTenue)),
                    const SizedBox(height: 4),
                    Text(
                      widget.tipo.concepto,
                      style: const TextStyle(color: FpColores.sidebar, fontSize: 18, fontWeight: FontWeight.w600),
                    ),
                    const SizedBox(height: 8),
                    Text(
                      formatoPrecio(monto),
                      style: tema.textTheme.headlineSmall?.copyWith(
                        fontWeight: FontWeight.bold,
                        color: tema.colorScheme.primary,
                      ),
                    ),
                  ],
                ),
              ),
              const SizedBox(height: 16),
              if (_resultado != null) ...[
                _AvisoResultado(resultado: _resultado!),
                const SizedBox(height: 16),
              ],
              if (_error != null) ...[
                _Aviso(icono: Icons.error_outline, texto: _error!, color: tema.colorScheme.error),
                const SizedBox(height: 16),
              ],
              if (_pagado || _noCuadra)
                FilledButton(
                  onPressed: () => Navigator.of(context).pop(true),
                  style: FilledButton.styleFrom(minimumSize: const Size.fromHeight(52)),
                  child: const Text('Volver al pedido'),
                )
              else ...[
                FilledButton.icon(
                  key: const Key('abrir_pago_tarjeta'),
                  onPressed: _trabajando ? null : _pagar,
                  style: FilledButton.styleFrom(minimumSize: const Size.fromHeight(52)),
                  icon: const Icon(Icons.credit_card),
                  label: Text(_porVerificar ? 'Abrir el pago con tarjeta otra vez' : 'Pagar con tarjeta'),
                ),
                if (_porVerificar) ...[
                  const SizedBox(height: 12),
                  OutlinedButton.icon(
                    onPressed: _trabajando ? null : _verificar,
                    style: OutlinedButton.styleFrom(minimumSize: const Size.fromHeight(52)),
                    icon: const Icon(Icons.refresh),
                    label: const Text('Verificar pago'),
                  ),
                ],
                if (_trabajando) ...[
                  const SizedBox(height: 16),
                  const Center(child: CircularProgressIndicator()),
                ],
                const SizedBox(height: 16),
                Text(
                  _porVerificar
                      ? 'Cuando termines de pagar en Mercado Pago, regresa a la app: revisaremos tu pago solos. '
                          'Si no se actualiza, toca "Verificar pago".'
                      : 'Se abre Mercado Pago para cobrar la tarjeta. También puedes pagar en mostrador.',
                  style: tema.textTheme.bodyMedium,
                ),
              ],
            ],
          ),
        ),
      ),
    );
  }
}

class _AvisoResultado extends StatelessWidget {
  const _AvisoResultado({required this.resultado});

  final ResultadoPagoMercadoPago resultado;

  @override
  Widget build(BuildContext context) {
    final (icono, color) = switch (resultado.resultado) {
      'aplicado' => (Icons.check_circle_outline, FpColores.insigniaExitoTexto),
      'pendiente' => (Icons.hourglass_top_outlined, FpColores.primario),
      _ => (Icons.info_outline, Theme.of(context).colorScheme.error),
    };

    return _Aviso(icono: icono, texto: resultado.mensaje, color: color);
  }
}

class _Aviso extends StatelessWidget {
  const _Aviso({required this.icono, required this.texto, required this.color});

  final IconData icono;
  final String texto;
  final Color color;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: color.withValues(alpha: 0.08),
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: color.withValues(alpha: 0.25)),
      ),
      child: Row(
        children: [
          Icon(icono, color: color),
          const SizedBox(width: 10),
          Expanded(child: Text(texto)),
        ],
      ),
    );
  }
}
