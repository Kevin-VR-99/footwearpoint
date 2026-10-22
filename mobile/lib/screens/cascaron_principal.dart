import 'dart:async';

import 'package:firebase_messaging/firebase_messaging.dart';

import '../services/avisos_en_vivo.dart';
import 'notificaciones_screen.dart';

import 'package:flutter/material.dart';

import 'package:provider/provider.dart';

import '../providers/auth_provider.dart';
import '../tema/fp_colores.dart';
import 'catalogo_screen.dart';
import 'inicio_screen.dart';
import 'mis_pedidos_screen.dart';
import 'perfil_screen.dart';
import 'vales_screen.dart';

/// La barra de abajo con las cinco secciones (TG-281, punto 5).
///
/// Antes todo colgaba del Inicio: para ir de Vales a Catálogo había que
/// volver atrás y entrar otra vez. Con la barra se salta de una a otra de un
/// toque, que es como funcionan las apps que la gente ya usa.
///
/// Cada pestaña se arma la PRIMERA vez que se entra, no al abrir la app: si
/// se armaran todas de golpe, al entrar se dispararían cuatro peticiones al
/// servidor (catálogo, pedidos, vales y perfil) para ver una sola pantalla.
/// Una vez armada se queda viva, así que al volver no se recarga ni se
/// pierde dónde ibas.
///
/// Lo que no está en la barra —el carrito y los clientes particulares—
/// se sigue abriendo desde el Inicio.
class CascaronPrincipal extends StatefulWidget {
  const CascaronPrincipal({super.key});

  @override
  State<CascaronPrincipal> createState() => _CascaronPrincipalState();
}

class _CascaronPrincipalState extends State<CascaronPrincipal> {
  int _actual = 0;

  /// Null mientras esa pestaña no se haya abierto nunca.
  final List<Widget?> _pestanas = List<Widget?>.filled(5, null);

  static const _secciones = <_Seccion>[
    _Seccion('Inicio', Icons.home_outlined, Icons.home),
    _Seccion('Catálogo', Icons.storefront_outlined, Icons.storefront),
    _Seccion('Pedidos', Icons.receipt_long_outlined, Icons.receipt_long),
    _Seccion(
      'Vales',
      Icons.confirmation_number_outlined,
      Icons.confirmation_number,
    ),
    _Seccion('Perfil', Icons.person_outline, Icons.person),
  ];

  Widget _construir(int indice) => switch (indice) {
    0 => const InicioScreen(),
    1 => const CatalogoScreen(),
    2 => const MisPedidosScreen(),
    3 => const ValesScreen(),
    _ => const PerfilScreen(),
  };

  void _ir(int indice) {
    setState(() {
      _pestanas[indice] ??= _construir(indice);
      _actual = indice;
    });
  }

  StreamSubscription<RemoteMessage>? _avisos;

  @override
  void initState() {
    super.initState();
    _pestanas[0] = _construir(0);

    // TG-281: con la app abierta, Android no enseña el aviso del sistema: se
    // lo entrega a la app. Aqui se enseña dentro, que ademas es lo correcto:
    // una notificacion del sistema encima de la app que ya estas usando
    // sobra, y asi se puede tocar para ir directo a la bandeja.
    _avisos = AvisosEnVivo.alLlegar.listen(_mostrar);
  }

  @override
  void dispose() {
    _avisos?.cancel();
    super.dispose();
  }

  void _mostrar(RemoteMessage mensaje) {
    if (!mounted) return;

    final titulo = mensaje.notification?.title ?? 'Tienes un aviso nuevo';
    final cuerpo = mensaje.notification?.body;

    ScaffoldMessenger.of(context)
      ..hideCurrentMaterialBanner()
      ..showMaterialBanner(
        MaterialBanner(
          backgroundColor: FpColores.insigniaInfoFondo,
          leading: const Icon(
            Icons.notifications_active_outlined,
            color: FpColores.insigniaInfoTexto,
          ),
          content: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                titulo,
                style: const TextStyle(
                  color: FpColores.insigniaInfoTexto,
                  fontWeight: FontWeight.w600,
                ),
              ),
              if (cuerpo != null && cuerpo.isNotEmpty) ...[
                const SizedBox(height: 2),
                Text(
                  cuerpo,
                  maxLines: 3,
                  overflow: TextOverflow.ellipsis,
                  style: const TextStyle(
                    color: FpColores.insigniaInfoTexto,
                    fontSize: 13.5,
                  ),
                ),
              ],
            ],
          ),
          actions: [
            TextButton(
              onPressed: () {
                ScaffoldMessenger.of(context).hideCurrentMaterialBanner();
                Navigator.of(context).push(
                  MaterialPageRoute<void>(
                    builder: (_) => const NotificacionesScreen(),
                  ),
                );
              },
              child: const Text('Ver'),
            ),
            TextButton(
              onPressed: ScaffoldMessenger.of(context)
                  .hideCurrentMaterialBanner,
              child: const Text('Cerrar'),
            ),
          ],
        ),
      );
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      // IndexedStack y no un simple if: así la pestaña que se deja atrás
      // conserva su estado (lo que se habia buscado, donde iba la lista).
      body: Column(
        children: [
          // TG-281: si la app se abrio con la copia guardada, hay que
          // decirlo. Lo que se ve puede estar viejo, y quien lo mira tiene
          // derecho a saberlo antes de tomar una decision con esos numeros.
          if (context.watch<AuthProvider>().conDatosGuardados)
            const _AvisoSinConexion(),
          Expanded(
            child: IndexedStack(
              index: _actual,
              children: [
                for (var i = 0; i < _secciones.length; i++)
                  // Las que todavia no se han abierto ocupan su lugar sin armarse.
                  _pestanas[i] ?? const SizedBox.shrink(),
              ],
            ),
          ),
        ],
      ),
      bottomNavigationBar: NavigationBar(
        selectedIndex: _actual,
        onDestinationSelected: _ir,
        backgroundColor: Colors.white,
        indicatorColor: FpColores.insigniaInfoFondo,
        surfaceTintColor: Colors.transparent,
        height: 68,
        labelBehavior: NavigationDestinationLabelBehavior.alwaysShow,
        destinations: [
          for (final seccion in _secciones)
            NavigationDestination(
              icon: Icon(seccion.icono, color: FpColores.textoTenue),
              selectedIcon: Icon(
                seccion.iconoActivo,
                color: FpColores.primario,
              ),
              label: seccion.nombre,
              tooltip: seccion.nombre,
            ),
        ],
      ),
    );
  }
}

class _Seccion {
  const _Seccion(this.nombre, this.icono, this.iconoActivo);

  final String nombre;
  final IconData icono;
  final IconData iconoActivo;
}

/// La franja de "sin conexión" de arriba (TG-281).
class _AvisoSinConexion extends StatelessWidget {
  const _AvisoSinConexion();

  @override
  Widget build(BuildContext context) {
    return Material(
      color: FpColores.insigniaAvisoFondo,
      child: SafeArea(
        bottom: false,
        child: InkWell(
          onTap: context.read<AuthProvider>().reintentar,
          child: Padding(
            padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 10),
            child: Row(
              children: [
                const Icon(
                  Icons.wifi_off_rounded,
                  size: 18,
                  color: FpColores.insigniaAvisoTexto,
                ),
                const SizedBox(width: 10),
                const Expanded(
                  child: Text(
                    'Sin conexión. Estás viendo lo último que se guardó.',
                    style: TextStyle(
                      color: FpColores.insigniaAvisoTexto,
                      fontSize: 13,
                      fontWeight: FontWeight.w500,
                    ),
                  ),
                ),
                Text(
                  'Reintentar',
                  style: TextStyle(
                    color: FpColores.insigniaAvisoTexto,
                    fontSize: 13,
                    fontWeight: FontWeight.w700,
                    decoration: TextDecoration.underline,
                    decorationColor: FpColores.insigniaAvisoTexto,
                  ),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}
