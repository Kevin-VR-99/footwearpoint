import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../providers/auth_provider.dart';
import '../providers/carrito_revendedor_provider.dart';
import '../services/api_service.dart';
import '../services/notificacion_service.dart';
import '../tema/fp_colores.dart';
import '../widgets/fp_componentes.dart';
import 'catalogo_screen.dart';
import 'mis_pedidos_screen.dart';
import 'pedido_revendedor_screen.dart';
import 'perfil_screen.dart';
import 'clientes_privados_screen.dart';
import 'vales_screen.dart';
import 'notificaciones_screen.dart';

class InicioScreen extends StatelessWidget {
  const InicioScreen({super.key});

  /// El rol como se lee en pantalla. Si llegara uno nuevo, se muestra tal cual.
  static String nombreRol(String? rol) => switch (rol) {
    'revendedor' => 'Revendedor',
    'cliente_directo' => 'Cliente directo',
    null => '',
    _ => rol,
  };

  void _abrir(BuildContext context, Widget pantalla) {
    Navigator.of(context).push(MaterialPageRoute<void>(builder: (_) => pantalla));
  }

  @override
  Widget build(BuildContext context) {
    final auth = context.watch<AuthProvider>();
    final usuario = auth.usuario;
    final habilitado = !auth.ocupado;
    final primerNombre = (usuario?.nombre ?? '').trim().split(RegExp(r'\s+')).first;

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
            Flexible(child: Text('Footwear Point', overflow: TextOverflow.ellipsis)),
          ],
        ),
        bottom: const FpBordeMarca(),
        actions: [
          // TG-165: con el número de no leídas, como el panel web (TG-156).
          _CampanaNotificaciones(habilitada: habilitado),
          const SizedBox(width: 4),
          Padding(
            padding: const EdgeInsets.only(right: 12),
            child: FpAvatar(nombre: usuario?.nombre, tamano: 34),
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
                etiqueta: auth.rol == null ? null : nombreRol(auth.rol),
                titulo: primerNombre.isEmpty ? 'Hola' : 'Hola, $primerNombre',
                subtitulo: 'Tu catálogo, tus pedidos y tus vales en un solo lugar.',
                pie: const _SesionIniciada(),
              ),
              const SizedBox(height: 20),
              const FpTituloSeccion('Accesos'),
              FpAccion(
                destacada: true,
                icono: Icons.storefront_outlined,
                titulo: 'Ver catálogo',
                subtitulo: 'Productos por línea, tallas y colores',
                alTocar: habilitado ? () => _abrir(context, const CatalogoScreen()) : null,
              ),
              // TG-165: el carrito ahora se guarda en el teléfono; sin
              // este botón, al volver a abrir la app solo se llegaba a
              // él desde el detalle de un producto.
              if (auth.rol == 'revendedor') ...[
                const SizedBox(height: 10),
                _BotonCarrito(habilitado: habilitado),
              ],
              const SizedBox(height: 10),
              // TG-165: ver el estado de los pedidos propios desde la app.
              FpAccion(
                icono: Icons.receipt_long_outlined,
                titulo: 'Mis pedidos',
                subtitulo: 'Estado, pagos y saldo',
                alTocar: habilitado ? () => _abrir(context, const MisPedidosScreen()) : null,
              ),
              const SizedBox(height: 10),
              FpAccion(
                icono: Icons.group_outlined,
                titulo: 'Mis Clientes Particulares',
                subtitulo: 'Tu lista de clientes',
                alTocar: habilitado ? () => _abrir(context, const ClientesPrivadosScreen()) : null,
              ),
              const SizedBox(height: 10),
              FpAccion(
                icono: Icons.confirmation_number_outlined,
                titulo: 'Consultar y Aplicar Vales',
                subtitulo: 'Saldo y vigencia de tus vales',
                alTocar: habilitado ? () => _abrir(context, const ValesScreen()) : null,
              ),
              const SizedBox(height: 10),
              FpAccion(
                icono: Icons.person_outline,
                titulo: 'Mi perfil',
                subtitulo: 'Nombre, teléfono y contraseña',
                alTocar: habilitado ? () => _abrir(context, const PerfilScreen()) : null,
              ),
              const SizedBox(height: 20),
              const FpTituloSeccion('Tu cuenta'),
              FpTarjeta(
                child: Column(
                  children: [
                    _Dato(etiqueta: 'Nombre', valor: usuario?.nombre),
                    _Dato(etiqueta: 'Correo', valor: usuario?.email),
                    _Dato(etiqueta: 'Rol', valor: nombreRol(auth.rol)),
                    _Dato(
                      etiqueta: 'Distribuidora',
                      valor: auth.distribuidoraId?.toString(),
                      ultimo: true,
                    ),
                  ],
                ),
              ),
              const SizedBox(height: 20),
              OutlinedButton.icon(
                onPressed: habilitado ? () => context.read<AuthProvider>().logout() : null,
                style: OutlinedButton.styleFrom(
                  foregroundColor: FpColores.peligro,
                  side: BorderSide(color: FpColores.peligro.withValues(alpha: 0.3)),
                  minimumSize: const Size.fromHeight(48),
                ),
                icon: const Icon(Icons.logout_rounded),
                label: const Text('Cerrar sesión'),
              ),
            ],
          ),
        ),
      ),
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

class _Dato extends StatelessWidget {
  const _Dato({required this.etiqueta, required this.valor, this.ultimo = false});

  final String etiqueta;
  final String? valor;
  final bool ultimo;

  @override
  Widget build(BuildContext context) {
    final vacio = valor == null || valor!.isEmpty;

    return Container(
      padding: const EdgeInsets.symmetric(vertical: 10),
      decoration: ultimo
          ? null
          : const BoxDecoration(
              border: Border(bottom: BorderSide(color: FpColores.borde)),
            ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          SizedBox(
            width: 110,
            child: Text(
              etiqueta,
              style: const TextStyle(
                color: FpColores.textoTenue,
                fontSize: 13,
                fontWeight: FontWeight.w500,
              ),
            ),
          ),
          Expanded(
            child: Text(
              vacio ? '(vacío)' : valor!,
              style: vacio
                  ? TextStyle(color: Theme.of(context).colorScheme.error)
                  : const TextStyle(color: FpColores.texto, fontWeight: FontWeight.w500),
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

  @override
  void initState() {
    super.initState();
    _contar();
  }

  Future<void> _contar() async {
    try {
      final total = await _servicio.contarNoLeidas();
      if (mounted) setState(() => _noLeidas = total);
    } on ApiException {
      // Sin el número la campana sigue sirviendo; un 401 lo atiende AuthProvider.
    }
  }

  Future<void> _abrir() async {
    await Navigator.of(context)
        .push(MaterialPageRoute<void>(builder: (_) => const NotificacionesScreen()));
    _contar();
  }

  @override
  Widget build(BuildContext context) {
    return IconButton(
      tooltip: _noLeidas > 0 ? 'Notificaciones ($_noLeidas sin leer)' : 'Notificaciones',
      onPressed: widget.habilitada ? _abrir : null,
      icon: Badge(
        isLabelVisible: _noLeidas > 0,
        label: Text(_noLeidas > 9 ? '9+' : '$_noLeidas'),
        child: Icon(
          _noLeidas > 0 ? Icons.notifications_active_outlined : Icons.notifications_outlined,
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
      titulo: piezas > 0 ? 'Mi pedido acumulado ($piezas)' : 'Mi pedido acumulado',
      subtitulo: piezas > 0 ? 'Listo para revisar y enviar' : 'Tu carrito está vacío',
      alTocar: habilitado
          ? () =>
                Navigator.of(context)
                    .push(MaterialPageRoute<void>(builder: (_) => const PedidoRevendedorScreen()))
          : null,
    );
  }
}
