import 'dart:async';
import 'package:firebase_messaging/firebase_messaging.dart';
import '../services/avisos_en_vivo.dart';
import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../models/pedido_resumen.dart';
import '../models/vale.dart';
import '../models/estado_pedido.dart';
import '../services/pedido_service.dart';
import '../services/vale_service.dart';
import 'producto_detalle_screen.dart' show formatoPrecio;
import '../providers/auth_provider.dart';
import '../providers/carrito_revendedor_provider.dart';
import '../services/api_service.dart';
import '../services/notificacion_service.dart';
import '../tema/fp_colores.dart';
import '../widgets/fp_componentes.dart';
import '../widgets/recarga_de_pestana.dart';
import 'mis_pedidos_screen.dart';
import 'pedido_revendedor_screen.dart' hide formatoPrecio;
import 'perfil_screen.dart';
import 'clientes_privados_screen.dart';
import 'notificaciones_screen.dart';

class InicioScreen extends StatefulWidget {
  const InicioScreen({super.key, this.recarga});

  /// El cascarón la usa para volver a pedir las cifras al regresar al Inicio.
  final RecargaDePestana? recarga;

  /// El rol como se lee en pantalla. Si llegara uno nuevo, se muestra tal cual.
  static String nombreRol(String? rol) => switch (rol) {
    'revendedor' => 'Cliente mayorista',
    'cliente_directo' => 'Cliente directo',
    null => '',
    _ => rol,
  };

  @override
  State<InicioScreen> createState() => _InicioScreenState();
}

class _InicioScreenState extends State<InicioScreen> {
  ResumenPedidos? _resumen;
  List<Vale>? _vales;

  /// True mientras de verdad se esta pidiendo el resumen.
  bool _cargandoResumen = true;

  @override
  void initState() {
    super.initState();
    widget.recarga?.registrar(_cargarResumen);
    _cargarResumen();
  }

  /// TG-281: las cifras de arriba, como las del Inicio del panel web.
  ///
  /// Si alguna peticion falla no se enseña ningun error: el Inicio sigue
  /// sirviendo para navegar, y una tarjeta con un guion molesta menos que
  /// una pantalla de error por un numero.
  Future<void> _cargarResumen() async {
    final api = context.read<AuthProvider>().api;

    // Se atrapa cualquier cosa, no solo ApiException: si el servidor
    // contestara con una forma rara, el error seria un TypeError y tumbaria
    // el Inicio entero por un numero de adorno. La pantalla tiene que seguir
    // sirviendo para navegar aunque el resumen no se pueda armar.
    try {
      final resumen = await PedidoService(api).resumen();
      if (mounted) setState(() => _resumen = resumen);
    } catch (_) {
      // Se queda con el guion.
    }

    try {
      final vales = await ValeService(api: api).listarValesVigentes();
      if (mounted) setState(() => _vales = vales);
    } catch (_) {
      // Igual.
    }

    if (mounted) setState(() => _cargandoResumen = false);
  }

  void _abrir(BuildContext context, Widget pantalla) {
    Navigator.of(context)
        .push(MaterialPageRoute<void>(builder: (_) => pantalla));
  }

  @override
  Widget build(BuildContext context) {
    final auth = context.watch<AuthProvider>();
    final usuario = auth.usuario;
    final habilitado = !auth.ocupado;
    final primerNombre = (usuario?.nombre ?? '')
        .trim()
        .split(RegExp(r'\s+'))
        .first;

    // Diseño (TG-165): como el Inicio del panel web. Encabezado con barra
    // azul, accesos en tarjetas y los datos de la cuenta abajo. Mismos
    // botones y a las mismas pantallas que antes.
    return Scaffold(
      appBar: AppBar(
        titleSpacing: 16,
        title: const Row(
          children: [
            FpLogo(tamano: 34),
            SizedBox(width: 10),
            // En celulares angostos se recorta en vez de desbordarse.
            Flexible(
              child: Text('Footwear Point', overflow: TextOverflow.ellipsis),
            ),
          ],
        ),
        bottom: const FpBordeMarca(),
        actions: [
          // TG-165: con el número de no leídas, como el panel web (TG-156).
          _CampanaNotificaciones(habilitada: habilitado),
          const SizedBox(width: 4),
          // TG-281: antes era solo un adorno. Ahora es la puerta a Mi
          // perfil, que es donde la gente la busca.
          Padding(
            padding: const EdgeInsets.only(right: 12),
            child: Semantics(
              button: true,
              label: 'Mi perfil',
              child: Tooltip(
                message: 'Mi perfil',
                child: InkWell(
                  customBorder: const CircleBorder(),
                  onTap: habilitado
                      ? () => _abrir(context, const PerfilScreen())
                      : null,
                  child: FpAvatar(nombre: usuario?.nombre, tamano: 34),
                ),
              ),
            ),
          ),
        ],
      ),
      body: SafeArea(
        // Un scroll normal (no ListView): arma todos los botones desde el
        // principio, también los de abajo que todavía no se ven.
        child: SingleChildScrollView(
          padding: const EdgeInsets.fromLTRB(16, 16, 16, 24),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              FpEncabezado(
                etiqueta: auth.rol == null
                    ? null
                    : InicioScreen.nombreRol(auth.rol),
                titulo: primerNombre.isEmpty ? 'Hola' : 'Hola, $primerNombre',
                subtitulo:
                    'Tu catálogo, tus pedidos y tus vales en un solo lugar.',
                pie: const _SesionIniciada(),
              ),
              const SizedBox(height: 20),
              // TG-281: el Inicio enseña numeros, como el del panel web.
              // Antes solo tenia botones para ir a otro lado; ahora de un
              // vistazo se sabe como van los pedidos y los vales.
              const FpTituloSeccion('Tu resumen'),
              _Cifras(
                enCurso: _resumen?.enCurso,
                porPagar: _resumen?.porPagar,
                cargado: _resumen != null,
                vales: _vales,
                cargando: _cargandoResumen,
              ),
              // Solo lo que NO esta en la barra de abajo, y todo eso es del
              // cliente mayorista. TG-281: al cliente directo no se le
              // enseña el titulo "Accesos" con nada debajo.
              if (auth.rol == 'revendedor') ...[
                const SizedBox(height: 20),
                const FpTituloSeccion('Accesos'),
              ],
              if (auth.rol == 'revendedor') ...[
                _BotonCarrito(habilitado: habilitado),
                const SizedBox(height: 10),
              ],
              // Los clientes particulares son solo del revendedor (E9-06):
              // a un cliente directo el servidor le contesta 403 y la
              // pantalla se veía vacía o con error. Ya no se le enseña.
              if (auth.rol == 'revendedor')
                FpAccion(
                  icono: Icons.group_outlined,
                  titulo: 'Mis Clientes Particulares',
                  subtitulo: 'Tu lista de clientes',
                  alTocar: habilitado
                      ? () => _abrir(context, const ClientesPrivadosScreen())
                      : null,
                ),
              _UltimosPedidos(
                pedidos: _resumen?.ultimos,
                alVerTodos: habilitado
                    ? () => _abrir(context, const MisPedidosScreen())
                    : null,
              ),
            ],
          ),
        ),
      ),
    );
  }
}

/// Las cifras de arriba del Inicio (TG-281), como las del panel web.
///
/// Mientras no llegan los datos se enseña un guion en vez de un cero: un
/// cero dice "no tienes nada", y eso todavia no se sabe.
class _Cifras extends StatelessWidget {
  const _Cifras({
    required this.enCurso,
    required this.porPagar,
    required this.cargado,
    required this.vales,
    required this.cargando,
  });

  /// Ya vienen contados en el servidor, también si hay más de 100 pedidos.
  final int? enCurso;
  final double? porPagar;
  final bool cargado;
  final List<Vale>? vales;

  /// Si ya se intento y no se pudo, se dice. No se deja un "Cargando..."
  /// puesto para siempre: sin internet eso no se quita nunca y es mentira.
  final bool cargando;

  String get _sinDato => cargando ? 'Cargando…' : 'No se pudo cargar';

  @override
  Widget build(BuildContext context) {
    final saldoVales = vales?.fold<double>(
      0,
      (suma, v) => suma + v.saldoActual,
    );

    // IntrinsicHeight + stretch: las tres quedan de la misma altura aunque
    // una lleve el texto en dos renglones. Sin esto se ven desparejas.
    return IntrinsicHeight(
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Expanded(
            child: FpDato(
              etiqueta: 'Pedidos en curso',
              valor: cargado ? '$enCurso' : '—',
              nota: cargado ? 'Sin contar los entregados' : _sinDato,
            ),
          ),
          const SizedBox(width: 10),
          Expanded(
            child: FpDato(
              etiqueta: 'Te falta pagar',
              valor: cargado ? formatoPrecio(porPagar ?? 0) : '—',
              nota: cargado ? 'De tus pedidos en curso' : _sinDato,
              colorValor: (porPagar ?? 0) > 0
                  ? FpColores.peligro
                  : FpColores.sidebar,
            ),
          ),
          const SizedBox(width: 10),
          Expanded(
            child: FpDato(
              etiqueta: 'Tus vales',
              valor: saldoVales == null ? '—' : formatoPrecio(saldoVales),
              nota: vales == null
                  ? _sinDato
                  : vales!.length == 1
                  ? '1 vale vigente'
                  : '${vales!.length} vales vigentes',
              colorValor: (saldoVales ?? 0) > 0
                  ? FpColores.insigniaExitoTexto
                  : FpColores.sidebar,
            ),
          ),
        ],
      ),
    );
  }
}

/// Los ultimos pedidos, como la tabla "Pedidos recientes" del panel web.
///
/// Solo tres: el Inicio es para ver como va todo de un vistazo, no para
/// repetir la pantalla de pedidos, que esta a un toque en la barra de abajo.
class _UltimosPedidos extends StatelessWidget {
  const _UltimosPedidos({required this.pedidos, required this.alVerTodos});

  final List<PedidoResumen>? pedidos;
  final VoidCallback? alVerTodos;

  @override
  Widget build(BuildContext context) {
    final lista = pedidos;

    // Sin datos todavia, o sin pedidos: no se enseña una seccion vacia.
    // El servidor ya manda solo los tres más nuevos.
    if (lista == null || lista.isEmpty) return const SizedBox.shrink();

    final recientes = lista;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        const SizedBox(height: 20),
        Row(
          children: [
            const Expanded(child: FpTituloSeccion('Tus últimos pedidos')),
            TextButton(onPressed: alVerTodos, child: const Text('Ver todos')),
          ],
        ),
        for (final pedido in recientes) ...[
          FpTarjeta(
            child: Row(
              children: [
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        pedido.folio,
                        style: const TextStyle(
                          fontWeight: FontWeight.w600,
                          color: FpColores.sidebar,
                        ),
                      ),
                      const SizedBox(height: 4),
                      EtiquetaEstadoPedido(estado: pedido.estado),
                    ],
                  ),
                ),
                Text(
                  formatoPrecio(pedido.total),
                  style: const TextStyle(
                    fontSize: 15,
                    fontWeight: FontWeight.w600,
                    color: FpColores.sidebar,
                  ),
                ),
              ],
            ),
          ),
          const SizedBox(height: 8),
        ],
      ],
    );
  }
}

/// La píldora con punto verde del encabezado.
class _SesionIniciada extends StatelessWidget {
  const _SesionIniciada();

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
      decoration: ShapeDecoration(
        color: FpColores.insigniaExitoFondo,
        shape: const StadiumBorder(),
      ),
      child: const Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          SizedBox(
            width: 6,
            height: 6,
            child: DecoratedBox(
              decoration: BoxDecoration(
                color: FpColores.insigniaExitoTexto,
                shape: BoxShape.circle,
              ),
            ),
          ),
          SizedBox(width: 8),
          Text(
            'Sesión iniciada',
            style: TextStyle(
              color: FpColores.insigniaExitoTexto,
              fontSize: 12,
              fontWeight: FontWeight.w500,
            ),
          ),
        ],
      ),
    );
  }
}

/// La campana de la barra de arriba, con un globito del número de
/// notificaciones sin leer. Se vuelve a contar al regresar de la bandeja.
class _CampanaNotificaciones extends StatefulWidget {
  const _CampanaNotificaciones({required this.habilitada});

  final bool habilitada;

  @override
  State<_CampanaNotificaciones> createState() => _CampanaNotificacionesState();
}

class _CampanaNotificacionesState extends State<_CampanaNotificaciones> {
  late final _servicio = NotificacionService(context.read<AuthProvider>().api);
  int _noLeidas = 0;

  StreamSubscription<RemoteMessage>? _avisos;

  @override
  void initState() {
    super.initState();
    _contar();

    // TG-281: si llega un aviso con la app abierta, el globito rojo tiene
    // que aparecer solo. Antes habia que tocar la campanita para enterarse,
    // que es justo al reves de para lo que sirve.
    // El conteo se guarda unos minutos. Un aviso nuevo tiene que saltarse
    // esa copia: si no, el globito sigue en cero hasta que venza.
    _avisos = AvisosEnVivo.alLlegar.listen((_) {
      NotificacionService.olvidarCache();
      _contar(forzar: true);
    });
  }

  @override
  void dispose() {
    _avisos?.cancel();
    super.dispose();
  }

  Future<void> _contar({bool forzar = false}) async {
    try {
      final total = await _servicio.contarNoLeidas(forzar: forzar);
      if (mounted) setState(() => _noLeidas = total);
    } on ApiException {
      // Sin el número la campana sigue sirviendo; un 401 lo atiende AuthProvider.
    }
  }

  Future<void> _abrir() async {
    await Navigator.of(context).push(
      MaterialPageRoute<void>(builder: (_) => const NotificacionesScreen()),
    );
    _contar(forzar: true);
  }

  @override
  Widget build(BuildContext context) {
    return IconButton(
      tooltip: _noLeidas > 0
          ? 'Notificaciones ($_noLeidas sin leer)'
          : 'Notificaciones',
      onPressed: widget.habilitada ? _abrir : null,
      icon: Badge(
        isLabelVisible: _noLeidas > 0,
        label: Text(_noLeidas > 9 ? '9+' : '$_noLeidas'),
        child: Icon(
          _noLeidas > 0
              ? Icons.notifications_active_outlined
              : Icons.notifications_outlined,
        ),
      ),
    );
  }
}

/// "Mi pedido acumulado", con cuántas piezas lleva el carrito.
class _BotonCarrito extends StatelessWidget {
  const _BotonCarrito({required this.habilitado});

  final bool habilitado;

  @override
  Widget build(BuildContext context) {
    final piezas = context.watch<CarritoRevendedorProvider>().totalPiezas;

    return FpAccion(
      icono: Icons.shopping_cart_outlined,
      titulo: piezas > 0
          ? 'Mi pedido acumulado ($piezas)'
          : 'Mi pedido acumulado',
      subtitulo: piezas > 0
          ? 'Listo para revisar y enviar'
          : 'Tu carrito está vacío',
      alTocar: habilitado
          ? () => Navigator.of(context).push(
              MaterialPageRoute<void>(
                builder: (_) => const PedidoRevendedorScreen(),
              ),
            )
          : null,
    );
  }
}
