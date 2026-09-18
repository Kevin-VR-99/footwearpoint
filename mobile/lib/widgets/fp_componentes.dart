import 'package:flutter/material.dart';

import '../tema/fp_colores.dart';

/// Piezas de diseño con el estilo del panel web (TG-165). Solo dibujan: no
/// piden nada al servidor ni guardan estado.

/// La línea delgada con degradado azul -> marino -> rojo que la web pone
/// arriba del encabezado y del menú.
class FpLineaMarca extends StatelessWidget {
  const FpLineaMarca({super.key, this.alto = 2});

  final double alto;

  @override
  Widget build(BuildContext context) {
    return Container(
      height: alto,
      decoration: const BoxDecoration(gradient: FpColores.degradadoMarca),
    );
  }
}

/// La misma línea, para ponerla en el `bottom` de un AppBar.
class FpBordeMarca extends StatelessWidget implements PreferredSizeWidget {
  const FpBordeMarca({super.key});

  @override
  Size get preferredSize => const Size.fromHeight(2);

  @override
  Widget build(BuildContext context) => const FpLineaMarca();
}

/// El logo circular de Footwear Point, como en el menú de la web.
class FpLogo extends StatelessWidget {
  const FpLogo({super.key, this.tamano = 40});

  final double tamano;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: tamano,
      height: tamano,
      padding: EdgeInsets.all(tamano * 0.05),
      decoration: BoxDecoration(
        color: Colors.white,
        shape: BoxShape.circle,
        border: Border.all(color: FpColores.primario.withValues(alpha: 0.35), width: 2),
      ),
      child: ClipOval(
        child: Image.asset(
          'assets/brand/logo-mark-white-192.png',
          fit: BoxFit.cover,
          // Si la imagen no cargara, no se rompe la pantalla.
          errorBuilder: (_, _, _) => const Icon(Icons.storefront, color: FpColores.sidebar),
        ),
      ),
    );
  }
}

/// Círculo con las iniciales del usuario, como el avatar del panel web.
class FpAvatar extends StatelessWidget {
  const FpAvatar({super.key, required this.nombre, this.tamano = 36});

  final String? nombre;
  final double tamano;

  static String iniciales(String? nombre) {
    final partes = (nombre ?? '').trim().split(RegExp(r'\s+')).where((p) => p.isNotEmpty).take(2);
    final texto = partes.map((p) => p.substring(0, 1).toUpperCase()).join();
    return texto.isEmpty ? 'FP' : texto;
  }

  @override
  Widget build(BuildContext context) {
    return Container(
      width: tamano,
      height: tamano,
      alignment: Alignment.center,
      decoration: BoxDecoration(
        shape: BoxShape.circle,
        gradient: const LinearGradient(
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
          colors: [FpColores.sidebar, FpColores.acento],
        ),
        border: Border.all(color: FpColores.primario.withValues(alpha: 0.15), width: 2),
      ),
      child: Text(
        iniciales(nombre),
        style: TextStyle(color: Colors.white, fontSize: tamano * 0.34, fontWeight: FontWeight.w600),
      ),
    );
  }
}

/// El encabezado de sección de la web: tarjeta blanca con barra azul a la
/// izquierda, círculos de color a la derecha, una etiqueta en mayúsculas
/// pequeñas, el título y un texto opcional.
class FpEncabezado extends StatelessWidget {
  const FpEncabezado({
    super.key,
    required this.titulo,
    this.etiqueta,
    this.subtitulo,
    this.pie,
  });

  final String titulo;

  /// Texto chico en mayúsculas, arriba del título (p. ej. "MIS PEDIDOS").
  final String? etiqueta;
  final String? subtitulo;

  /// Algo debajo del texto: por ejemplo, una insignia o un aviso.
  final Widget? pie;

  @override
  Widget build(BuildContext context) {
    final tema = Theme.of(context);

    return FpTarjeta(
      padding: EdgeInsets.zero,
      child: Stack(
        children: [
          // Círculos decorativos (bg-fp-danger/10 y bg-fp-primary/10).
          Positioned(
            right: -40,
            top: -40,
            child: _Circulo(tamano: 128, color: FpColores.peligro.withValues(alpha: 0.10)),
          ),
          Positioned(
            right: -8,
            top: 40,
            child: _Circulo(tamano: 64, color: FpColores.primario.withValues(alpha: 0.10)),
          ),
          // Barra azul a la izquierda.
          const Positioned(
            left: 0,
            top: 0,
            bottom: 0,
            child: SizedBox(width: 6, child: ColoredBox(color: FpColores.primario)),
          ),
          Padding(
            padding: const EdgeInsets.fromLTRB(22, 18, 20, 18),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                if (etiqueta != null)
                  Text(
                    etiqueta!.toUpperCase(),
                    style: const TextStyle(
                      color: FpColores.primario,
                      fontSize: 11,
                      fontWeight: FontWeight.w600,
                      letterSpacing: 1.6,
                    ),
                  ),
                if (etiqueta != null) const SizedBox(height: 4),
                Text(
                  titulo,
                  style: tema.textTheme.headlineSmall?.copyWith(
                    color: FpColores.sidebar,
                    fontWeight: FontWeight.bold,
                    letterSpacing: -0.4,
                  ),
                ),
                if (subtitulo != null) ...[
                  const SizedBox(height: 4),
                  Text(subtitulo!, style: const TextStyle(color: FpColores.textoTenue, fontSize: 14)),
                ],
                if (pie != null) ...[
                  const SizedBox(height: 12),
                  pie!,
                ],
              ],
            ),
          ),
        ],
      ),
    );
  }
}

class _Circulo extends StatelessWidget {
  const _Circulo({required this.tamano, required this.color});

  final double tamano;
  final Color color;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: tamano,
      height: tamano,
      decoration: BoxDecoration(color: color, shape: BoxShape.circle),
    );
  }
}

/// Tarjeta blanca de la web (rounded-2xl, borde suave, sombra leve). Con
/// [alTocar] se puede tocar.
class FpTarjeta extends StatelessWidget {
  const FpTarjeta({
    super.key,
    required this.child,
    this.padding = const EdgeInsets.all(16),
    this.alTocar,
    this.color,
  });

  final Widget child;
  final EdgeInsetsGeometry padding;
  final VoidCallback? alTocar;
  final Color? color;

  @override
  Widget build(BuildContext context) {
    return Card(
      margin: EdgeInsets.zero,
      color: color,
      clipBehavior: Clip.antiAlias,
      child: InkWell(
        onTap: alTocar,
        child: Padding(padding: padding, child: child),
      ),
    );
  }
}

/// Título de una sección dentro de una pantalla ("Productos", "Pagos"...).
class FpTituloSeccion extends StatelessWidget {
  const FpTituloSeccion(this.texto, {super.key, this.accion});

  final String texto;
  final Widget? accion;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(left: 4, bottom: 8),
      child: Row(
        children: [
          Expanded(
            child: Text(
              texto,
              style: const TextStyle(color: FpColores.sidebar, fontSize: 16, fontWeight: FontWeight.w600),
            ),
          ),
          ?accion,
        ],
      ),
    );
  }
}

/// El cuadrito de color con un ícono que la web pone en sus tarjetas.
class FpIconoCuadro extends StatelessWidget {
  const FpIconoCuadro({
    super.key,
    required this.icono,
    this.fondo = const Color(0x1A2563EB), // bg-fp-primary/10
    this.color = FpColores.primario,
    this.tamano = 40,
  });

  final IconData icono;
  final Color fondo;
  final Color color;
  final double tamano;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: tamano,
      height: tamano,
      decoration: BoxDecoration(color: fondo, borderRadius: BorderRadius.circular(12)),
      child: Icon(icono, color: color, size: tamano * 0.5),
    );
  }
}

/// Tarjeta de acción del Inicio web: la [destacada] va en azul con un
/// círculo decorativo ("Nuevo pedido"); las demás, blancas con su ícono.
class FpAccion extends StatelessWidget {
  const FpAccion({
    super.key,
    required this.titulo,
    required this.icono,
    required this.alTocar,
    this.subtitulo,
    this.destacada = false,
    this.insignia,
  });

  final String titulo;
  final String? subtitulo;
  final IconData icono;

  /// Null la deshabilita (se ve más tenue y no se puede tocar).
  final VoidCallback? alTocar;
  final bool destacada;

  /// Un número en rojo sobre el ícono (p. ej. piezas en el carrito).
  final int? insignia;

  @override
  Widget build(BuildContext context) {
    final colorTitulo = destacada ? Colors.white : FpColores.sidebar;
    final colorTexto = destacada ? Colors.white.withValues(alpha: 0.8) : FpColores.textoTenue;

    Widget iconoCuadro = destacada
        ? Container(
            width: 40,
            height: 40,
            decoration: BoxDecoration(color: Colors.white.withValues(alpha: 0.15), shape: BoxShape.circle),
            child: Icon(icono, color: Colors.white, size: 20),
          )
        : FpIconoCuadro(icono: icono);

    if (insignia != null && insignia! > 0) {
      iconoCuadro = Badge(label: Text('${insignia!}'), child: iconoCuadro);
    }

    return Opacity(
      opacity: alTocar == null ? 0.6 : 1,
      child: Card(
        margin: EdgeInsets.zero,
        color: destacada ? FpColores.primario : Colors.white,
        clipBehavior: Clip.antiAlias,
        shape: destacada
            ? RoundedRectangleBorder(borderRadius: BorderRadius.circular(16))
            : null,
        child: InkWell(
          onTap: alTocar,
          child: Stack(
            children: [
              if (destacada)
                Positioned(
                  right: -24,
                  top: -24,
                  child: _Circulo(tamano: 96, color: Colors.white.withValues(alpha: 0.10)),
                ),
              Padding(
                padding: const EdgeInsets.all(16),
                child: Row(
                  children: [
                    iconoCuadro,
                    const SizedBox(width: 14),
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(
                            titulo,
                            style: TextStyle(color: colorTitulo, fontSize: 15, fontWeight: FontWeight.w600),
                          ),
                          if (subtitulo != null) ...[
                            const SizedBox(height: 2),
                            Text(subtitulo!, style: TextStyle(color: colorTexto, fontSize: 13)),
                          ],
                        ],
                      ),
                    ),
                    Icon(Icons.chevron_right_rounded, color: destacada ? Colors.white70 : FpColores.bordeFuerte),
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

/// Un dato con su etiqueta, como los recuadros de números de la web
/// ("Total", "Pagado", "Saldo").
class FpDato extends StatelessWidget {
  const FpDato({
    super.key,
    required this.etiqueta,
    required this.valor,
    this.nota,
    this.colorValor = FpColores.sidebar,
  });

  final String etiqueta;
  final String valor;
  final String? nota;
  final Color colorValor;

  @override
  Widget build(BuildContext context) {
    return FpTarjeta(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(etiqueta, style: const TextStyle(color: FpColores.textoTenue, fontSize: 12, fontWeight: FontWeight.w500)),
          const SizedBox(height: 6),
          Text(
            valor,
            style: TextStyle(color: colorValor, fontSize: 20, fontWeight: FontWeight.w600, letterSpacing: -0.3),
          ),
          if (nota != null) ...[
            const SizedBox(height: 4),
            Text(nota!, style: const TextStyle(color: FpColores.textoTenue, fontSize: 11)),
          ],
        ],
      ),
    );
  }
}

/// Aviso en píldora ("3 sin leer"), como el del encabezado del Inicio web.
class FpPildora extends StatelessWidget {
  const FpPildora({
    super.key,
    required this.texto,
    this.peligro = false,
    this.alTocar,
  });

  final String texto;
  final bool peligro;
  final VoidCallback? alTocar;

  @override
  Widget build(BuildContext context) {
    final color = peligro ? FpColores.peligro : FpColores.sidebar;

    return Material(
      color: peligro ? FpColores.peligroSuave : FpColores.pagina,
      shape: StadiumBorder(
        side: BorderSide(color: peligro ? FpColores.peligro.withValues(alpha: 0.2) : FpColores.borde),
      ),
      child: InkWell(
        onTap: alTocar,
        customBorder: const StadiumBorder(),
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
          child: Row(
            mainAxisSize: MainAxisSize.min,
            children: [
              if (peligro) ...[
                Container(
                  width: 6,
                  height: 6,
                  decoration: BoxDecoration(color: color, shape: BoxShape.circle),
                ),
                const SizedBox(width: 8),
              ],
              Text(texto, style: TextStyle(color: color, fontSize: 12, fontWeight: FontWeight.w500)),
            ],
          ),
        ),
      ),
    );
  }
}
