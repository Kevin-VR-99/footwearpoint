import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../models/notificacion.dart';
import '../models/pedido_resumen.dart';
import '../providers/auth_provider.dart';
import '../services/api_service.dart';
import '../services/notificacion_service.dart';
import 'pedido_detalle_screen.dart';
import '../widgets/fp_componentes.dart';
import '../tema/fp_colores.dart';

/// Cuanto se puede tocar "Deshacer" antes de que el borrado se mande de
/// verdad al servidor (TG-281).
const margenParaDeshacer = Duration(seconds: 4);

/// Bandeja de notificaciones dentro de la app (E16-01).
///
/// TG-165: antes solo mostraba la lista. Ahora las no leídas se distinguen,
/// al tocar una se marca como leída y, si es de un pedido, abre su detalle
/// (para eso el servidor manda entidad_tipo y entidad_id).
class NotificacionesScreen extends StatefulWidget {
  const NotificacionesScreen({super.key});

  @override
  State<NotificacionesScreen> createState() => _NotificacionesScreenState();
}

class _NotificacionesScreenState extends State<NotificacionesScreen> {
  // Con el ApiService de AuthProvider (no uno propio): así, si la sesión
  // expira aquí, el 401 regresa al login como en el resto de la app.
  late final _servicio = NotificacionService(context.read<AuthProvider>().api);

  List<Notificacion>? _notificaciones;

  /// Sube cuando un aviso que se habia quitado vuelve a su sitio.
  ///
  /// Va dentro de la llave de cada Dismissible. Si se repusiera con la misma
  /// llave, Flutter reusaria el estado del que ya se descarto y revienta con
  /// "a dismissed Dismissible widget is still part of the tree": el widget
  /// se cree descartado aunque la lista ya lo tenga otra vez.
  int _generacion = 0;

  bool _cargando = true;
  String? _error;

  @override
  void initState() {
    super.initState();
    _cargar();
  }

  Future<void> _cargar() async {
    try {
      final lista = await _servicio.listar();
      if (!mounted) return;
      setState(() {
        _notificaciones = lista;
        _error = null;
        _cargando = false;
      });
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _error = e.mensaje;
        _cargando = false;
      });
    }
  }

  /// TG-281: quita el aviso de la bandeja al deslizarlo.
  ///
  /// El borrado NO se manda enseguida: primero se quita de la pantalla y se
  /// espera a que el aviso de abajo se cierre. Si durante ese rato se toca
  /// "Deshacer", el aviso vuelve a su sitio y al servidor nunca se le pide
  /// nada. Si no, ahi si se borra.
  ///
  /// Se hace asi porque en el servidor no hay forma de des-borrar: una vez
  /// que se va, se fue. Un "Deshacer" que recargara la bandeja seria un
  /// boton que no deshace nada.
  Future<void> _borrar(Notificacion notificacion) async {
    final lista = _notificaciones;
    if (lista == null) return;

    final posicion = lista.indexOf(notificacion);
    if (posicion < 0) return;

    setState(() => lista.removeAt(posicion));

    final mensajero = ScaffoldMessenger.of(context);
    mensajero.hideCurrentSnackBar();

    var deshecho = false;

    void reponer() {
      if (!mounted) return;
      setState(() {
        lista.insert(posicion.clamp(0, lista.length), notificacion);
        _generacion++;
      });
    }

    mensajero.showSnackBar(
      SnackBar(
        duration: margenParaDeshacer,
        content: const Text('Aviso borrado.'),
        action: SnackBarAction(
          label: 'Deshacer',
          onPressed: () {
            deshecho = true;
            // Vuelve a la lista en el acto. Si se esperara a que termine el
            // margen, se tocaria "Deshacer" y no pasaria nada durante unos
            // segundos, que es justo lo que parece roto.
            reponer();
          },
        ),
      ),
    );

    // Se espera con un reloj propio y no con el futuro "closed" del aviso:
    // ese futuro depende de cuando se cierre la barra, y en el entorno de
    // pruebas no se cierra sola.
    await Future<void>.delayed(margenParaDeshacer + const Duration(milliseconds: 300));

    // Se arrepintio: al servidor nunca se le pidio nada.
    if (deshecho) return;

    try {
      await _servicio.borrar(notificacion.id);
    } on ApiException catch (e) {
      // El aviso sigue en el servidor, asi que tiene que volver a verse: es
      // peor que desaparezca de la pantalla algo que sigue existiendo.
      if (!mounted) return;
      reponer();
      // Se quita el "Aviso borrado.": ya no es verdad, y si se quedara en
      // cola el error no se veria hasta que aquel se fuera solo.
      mensajero.hideCurrentSnackBar();
      mensajero.showSnackBar(SnackBar(content: Text(e.mensaje)));
    }
  }

  Future<void> _tocar(Notificacion notificacion) async {
    if (!notificacion.leida) {
      // Se marca en pantalla de inmediato; si el servidor fallara, solo se
      // vería como leída hasta la próxima recarga (no es grave).
      setState(() {
        _notificaciones = [
          for (final n in _notificaciones!) n.id == notificacion.id ? n.marcadaLeida() : n,
        ];
      });

      try {
        await _servicio.marcarLeida(notificacion.id);
      } on ApiException {
        // Un 401 lo atiende AuthProvider; lo demás no vale la pena mostrarlo.
      }
    }

    final pedidoId = notificacion.pedidoId;
    if (pedidoId != null && mounted) {
      await Navigator.of(context).push(
        MaterialPageRoute<void>(builder: (_) => PedidoDetalleScreen(pedidoId: pedidoId)),
      );
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Notificaciones'), bottom: const FpBordeMarca()),
      body: SafeArea(child: _contenido(context)),
    );
  }

  Widget _contenido(BuildContext context) {
    if (_cargando) return const Center(child: CircularProgressIndicator());

    final lista = _notificaciones;
    if (lista == null) {
      return Center(
        child: FpEstadoVacio(
          error: true,
          icono: Icons.cloud_off_outlined,
          titulo: _error ?? 'No se pudieron cargar tus notificaciones.',
          accion: FilledButton.icon(
            onPressed: () {
              setState(() => _cargando = true);
              _cargar();
            },
            icon: const Icon(Icons.refresh),
            label: const Text('Reintentar'),
          ),
        ),
      );
    }

    return RefreshIndicator(
      onRefresh: _cargar,
      child: lista.isEmpty
          ? ListView(
              children: const [
                SizedBox(height: 80),
                FpEstadoVacio(
                  icono: Icons.notifications_none,
                  titulo: 'No tienes notificaciones recientes.',
                ),
              ],
            )
          : ListView.separated(
              padding: const EdgeInsets.all(16),
              itemCount: lista.length,
              separatorBuilder: (_, _) => const SizedBox(height: 8),
              itemBuilder: (_, i) => _Deslizable(
                // La key tiene que ser del aviso y no de su posicion: si
                // fuera la posicion, al borrar uno Flutter creeria que el de
                // abajo es el que se fue y animaria el equivocado.
                key: ValueKey('${lista[i].id}#$_generacion'),
                alBorrar: () => _borrar(lista[i]),
                child: _TarjetaNotificacion(
                  notificacion: lista[i],
                  alTocar: () => _tocar(lista[i]),
                ),
              ),
            ),
    );
  }
}

class _TarjetaNotificacion extends StatelessWidget {
  const _TarjetaNotificacion({required this.notificacion, required this.alTocar});

  final Notificacion notificacion;
  final VoidCallback alTocar;

  @override
  Widget build(BuildContext context) {
    final tema = Theme.of(context);
    final colores = tema.colorScheme;
    final nueva = !notificacion.leida;

    return Card(
      margin: EdgeInsets.zero,
      // Las no leídas con fondo de color, como en cualquier bandeja.
      color: nueva ? colores.primaryContainer.withValues(alpha: 0.5) : null,
      clipBehavior: Clip.antiAlias,
      child: InkWell(
        onTap: alTocar,
        child: Padding(
          padding: const EdgeInsets.all(16),
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              FpIconoCuadro(
                icono: nueva ? Icons.notifications_active_outlined : Icons.notifications_none,
                tamano: 36,
                fondo: nueva ? colores.primary.withValues(alpha: 0.12) : colores.surfaceContainerHighest,
                color: nueva ? colores.primary : colores.onSurfaceVariant,
              ),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Row(
                      children: [
                        Expanded(
                          child: Text(
                            notificacion.titulo,
                            style: TextStyle(fontWeight: nueva ? FontWeight.bold : FontWeight.w500),
                          ),
                        ),
                        if (nueva)
                          Tooltip(
                            message: 'Sin leer',
                            child: Container(
                              width: 10,
                              height: 10,
                              decoration: BoxDecoration(color: colores.primary, shape: BoxShape.circle),
                            ),
                          ),
                      ],
                    ),
                    const SizedBox(height: 4),
                    Text(notificacion.mensaje),
                    const SizedBox(height: 6),
                    Row(
                      children: [
                        if (notificacion.fecha != null)
                          Text(
                            formatoFecha(notificacion.fecha!),
                            style: tema.textTheme.bodySmall?.copyWith(color: colores.onSurfaceVariant),
                          ),
                        const Spacer(),
                        if (notificacion.pedidoId != null)
                          Text(
                            'Ver pedido',
                            style: tema.textTheme.bodySmall?.copyWith(
                              color: colores.primary,
                              fontWeight: FontWeight.w600,
                            ),
                          ),
                      ],
                    ),
                  ],
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

/// Envuelve un aviso para poder quitarlo deslizándolo (TG-281).
///
/// Solo hacia la derecha, como pidió el equipo. Hacia la izquierda no se
/// hace nada a propósito: con las dos direcciones es fácil borrar sin querer
/// al desplazarse en diagonal.
class _Deslizable extends StatelessWidget {
  const _Deslizable({super.key, required this.child, required this.alBorrar});

  final Widget child;
  final VoidCallback alBorrar;

  @override
  Widget build(BuildContext context) {
    return Dismissible(
      key: key!,
      direction: DismissDirection.startToEnd,
      // Hay que deslizar media tarjeta: con el valor de fabrica (0.4) se
      // borraba de un roce.
      dismissThresholds: const {DismissDirection.startToEnd: 0.5},
      onDismissed: (_) => alBorrar(),
      background: DecoratedBox(
        decoration: BoxDecoration(
          color: FpColores.peligro,
          borderRadius: BorderRadius.circular(12),
        ),
        child: const Align(
          alignment: Alignment.centerLeft,
          child: Padding(
            padding: EdgeInsets.only(left: 20),
            child: Row(
              mainAxisSize: MainAxisSize.min,
              children: [
                Icon(Icons.delete_outline, color: Colors.white),
                SizedBox(width: 8),
                Text(
                  'Borrar',
                  style: TextStyle(color: Colors.white, fontWeight: FontWeight.w600),
                ),
              ],
            ),
          ),
        ),
      ),
      child: child,
    );
  }
}
