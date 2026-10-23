import 'dart:async';

import 'package:flutter/material.dart';
import 'package:image_picker/image_picker.dart';
import 'package:provider/provider.dart';

import '../models/usuario.dart';
import '../providers/auth_provider.dart';
import '../services/api_service.dart';
import 'cambiar_password_screen.dart';
import 'inicio_screen.dart';
import 'login_screen.dart';
import '../tema/fp_colores.dart';
import '../widgets/fp_componentes.dart';

/// Ver y editar los datos propios (E1-05).
///
/// Al abrir pide GET /api/perfil para mostrar los datos actuales del
/// servidor, no los que la app recordaba del login. Solo se editan nombre y
/// teléfono; el correo es la llave de acceso y se muestra sin poder cambiarlo.
///
/// Al guardar, el servidor también actualiza el registro de revendedor o
/// cliente directo que ve el empleado en el panel web: eso no es trabajo de
/// la app.
///
/// El revendedor además cambia aquí su foto, su correo y su teléfono. El
/// correo y el teléfono no cambian al momento: el servidor manda un enlace
/// al correo nuevo y avisa al anterior; hasta que lo abre, el cambio se ve
/// como pendiente.
class PerfilScreen extends StatefulWidget {
  const PerfilScreen({super.key});

  /// Las pruebas lo cambian para no abrir la galería de verdad.
  static Future<FotoElegida?> Function() elegirFoto = _elegirDeGaleria;

  static Future<FotoElegida?> _elegirDeGaleria() async {
    final archivo = await ImagePicker().pickImage(
      source: ImageSource.gallery,
      maxWidth: 1024,
      maxHeight: 1024,
      imageQuality: 85,
    );
    if (archivo == null) return null;
    return FotoElegida(bytes: await archivo.readAsBytes(), nombre: archivo.name);
  }

  @override
  State<PerfilScreen> createState() => _PerfilScreenState();
}

class _PerfilScreenState extends State<PerfilScreen> {
  final _formulario = GlobalKey<FormState>();

  /// TG-189 (A11): mientras se pide otro enlace de confirmacion.
  bool _reenviando = false;
  bool _subiendoFoto = false;
  final _nombre = TextEditingController();
  final _telefono = TextEditingController();

  Usuario? _usuario;
  bool _cargando = true;
  bool _guardando = false;
  String? _errorCarga;
  String? _errorGuardar;

  /// Errores de validación que regresó Laravel, por campo.
  Map<String, List<String>> _erroresServidor = const {};

  @override
  void initState() {
    super.initState();
    _cargar();
  }

  @override
  void dispose() {
    _nombre.dispose();
    _telefono.dispose();
    super.dispose();
  }

  void _reintentarCarga() {
    setState(() {
      _cargando = true;
      _errorCarga = null;
    });
    _cargar();
  }

  /// La primera vez se llama desde initState, donde todavía no se puede usar
  /// setState: por eso [_cargando] ya empieza en true y aquí no se marca.
  Future<void> _cargar() async {
    final auth = context.read<AuthProvider>();

    try {
      final respuesta = await auth.api.get('perfil');
      final usuario = Usuario.desdeJson(respuesta['data'] as Map<String, dynamic>);

      if (!mounted) return;

      auth.actualizarUsuario(usuario);
      _mostrar(usuario);
      setState(() => _cargando = false);

      // TG-189 (A11): la persona confirma su correo FUERA de la app, así que
      // al volver aquí se vuelve a preguntar. Si ya lo hizo, el aviso se
      // quita solo. Solo cuesta una petición mientras falte confirmarlo.
      unawaited(auth.revisarCorreoConfirmado());
    } on ApiException catch (e) {
      if (!mounted) return;

      // TG-281: sin internet (codigoHttp null) no se enseña un error: se
      // enseñan los datos que ya se tenian de la sesion. El perfil es de los
      // dos sitios que deben abrirse sin conexion, y lo que hay ahi ya se
      // conoce. La franja de arriba avisa que puede estar viejo.
      final guardado = auth.usuario;

      if (e.codigoHttp == null && guardado != null) {
        _mostrar(guardado);
        setState(() {
          _cargando = false;
          _errorCarga = null;
        });

        return;
      }

      // Un 401 lo atiende AuthProvider (regresa al login). Lo demás se
      // muestra aquí con opción de reintentar.
      setState(() {
        _cargando = false;
        _errorCarga = e.mensaje;
      });
    }
  }

  void _mostrar(Usuario usuario) {
    _usuario = usuario;
    _nombre.text = usuario.nombre;
    _telefono.text = usuario.telefono ?? '';
  }

  /// El error que mandó el servidor para un campo se quita en cuanto la
  /// persona lo corrige. Mientras siga ahí, el campo no pasa la validación.
  void _limpiarErrorServidor(String campo) {
    if (!_erroresServidor.containsKey(campo)) return;

    setState(() {
      _erroresServidor = Map.of(_erroresServidor)..remove(campo);
    });
  }

  Future<void> _guardar() async {
    if (!_formulario.currentState!.validate()) return;

    FocusScope.of(context).unfocus();

    final auth = context.read<AuthProvider>();
    final mensajero = ScaffoldMessenger.of(context);

    setState(() {
      _guardando = true;
      _errorGuardar = null;
    });

    try {
      final respuesta = await auth.api.patch(
        'perfil',
        // El teléfono del revendedor solo cambia con confirmación por correo.
        cuerpo: _esRevendedor
            ? {'nombre': _nombre.text.trim()}
            : {'nombre': _nombre.text.trim(), 'telefono': _telefono.text.trim()},
      );
      final usuario = Usuario.desdeJson(respuesta['data'] as Map<String, dynamic>);

      auth.actualizarUsuario(usuario);

      if (!mounted) return;
      setState(() => _mostrar(usuario));

      mensajero.showSnackBar(
        SnackBar(content: Text(respuesta['message'] as String? ?? 'Datos actualizados.')),
      );
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _erroresServidor = e.errores;
        // Si el error es de un campo, ya se ve debajo de ese campo.
        _errorGuardar = e.errores.isEmpty ? e.mensaje : null;
      });
    } finally {
      if (mounted) setState(() => _guardando = false);
    }
  }

  bool get _esRevendedor => context.read<AuthProvider>().rol == 'revendedor';

  Future<void> _cambiarFoto() async {
    final auth = context.read<AuthProvider>();
    final mensajero = ScaffoldMessenger.of(context);

    final FotoElegida? foto;
    try {
      foto = await PerfilScreen.elegirFoto();
    } catch (_) {
      mensajero.showSnackBar(const SnackBar(content: Text('No se pudo abrir la galería.')));
      return;
    }
    if (foto == null || !mounted) return;

    setState(() => _subiendoFoto = true);
    try {
      final respuesta = await auth.api.subirArchivo(
        'perfil/foto',
        campo: 'foto',
        bytes: foto.bytes,
        nombreArchivo: foto.nombre,
      );
      final usuario = Usuario.desdeJson(respuesta['data'] as Map<String, dynamic>);
      auth.actualizarUsuario(usuario);
      if (!mounted) return;
      setState(() => _usuario = usuario);
      mensajero.showSnackBar(SnackBar(content: Text(respuesta['message'] as String? ?? 'Foto actualizada.')));
    } on ApiException catch (e) {
      mensajero.showSnackBar(SnackBar(content: Text(e.errores['foto']?.first ?? e.mensaje)));
    } finally {
      if (mounted) setState(() => _subiendoFoto = false);
    }
  }

  /// Pide el dato nuevo y lo manda. El servidor no lo cambia todavía: manda
  /// el enlace de confirmación y regresa el usuario con el cambio pendiente.
  Future<void> _pedirCambio({required String campo}) async {
    final auth = context.read<AuthProvider>();
    final mensajero = ScaffoldMessenger.of(context);
    final esCorreo = campo == 'email';

    final usuario = await showDialog<Usuario>(
      context: context,
      builder: (_) => _DialogoCambioContacto(
        api: auth.api,
        esCorreo: esCorreo,
        actual: esCorreo ? _usuario!.email : (_usuario!.telefono ?? ''),
      ),
    );
    if (usuario == null || !mounted) return;

    auth.actualizarUsuario(usuario);
    setState(() => _mostrar(usuario));
    mensajero.showSnackBar(
      SnackBar(
        content: Text(
          esCorreo
              ? 'Te mandamos un enlace a ${usuario.correoPendiente ?? 'tu correo nuevo'}. Ábrelo para confirmar el cambio.'
              : 'Te mandamos un enlace a tu correo. Ábrelo para confirmar el teléfono nuevo.',
        ),
      ),
    );
  }

  Future<void> _abrirCambiarPassword() async {
    final mensajero = ScaffoldMessenger.of(context);

    final mensaje = await Navigator.of(context)
        .push<String>(MaterialPageRoute(builder: (_) => const CambiarPasswordScreen()));

    if (mensaje != null) {
      mensajero.showSnackBar(SnackBar(content: Text(mensaje)));
    }
  }

  // Mismos máximos que ActualizarPerfilRequest del backend.
  String? _validarNombre(String? valor) {
    final nombre = valor?.trim() ?? '';
    if (nombre.isEmpty) return 'Escribe tu nombre.';
    if (nombre.length > 150) return 'El nombre no puede tener más de 150 caracteres.';
    return null;
  }

  String? _validarTelefono(String? valor) {
    if ((valor?.trim().length ?? 0) > 30) {
      return 'El teléfono no puede tener más de 30 caracteres.';
    }
    return null;
  }

  /// TG-189 (A11): el boton "no me llego". El mensaje lo escribe el
  /// servidor, y es el mismo haya mandado algo o no.
  Future<void> _reenviarConfirmacion() async {
    setState(() => _reenviando = true);

    final mensajero = ScaffoldMessenger.of(context);
    final auth = context.read<AuthProvider>();

    try {
      final mensaje = await auth.reenviarVerificacion();
      if (!mounted) return;
      mensajero.showSnackBar(SnackBar(content: Text(mensaje)));
    } on ApiException catch (e) {
      if (!mounted) return;
      mensajero.showSnackBar(SnackBar(content: Text(e.mensaje)));
    } finally {
      if (mounted) setState(() => _reenviando = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('Mi perfil'),
        bottom: const FpBordeMarca(),
      ),
      body: SafeArea(child: _contenido(context)),
    );
  }

  Widget _contenido(BuildContext context) {
    final auth = context.watch<AuthProvider>();
    final esRevendedor = auth.rol == 'revendedor';

    if (_cargando) {
      return const Center(child: CircularProgressIndicator());
    }

    if (_errorCarga != null) {
      return Center(
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              const FpIconoGrande(
                icono: Icons.person_off_outlined,
                fondo: FpColores.peligroSuave,
                color: FpColores.peligro,
              ),
              const SizedBox(height: 16),
              AvisoError(mensaje: _errorCarga!),
              const SizedBox(height: 16),
              FilledButton.icon(
                onPressed: _reintentarCarga,
                icon: const Icon(Icons.refresh),
                label: const Text('Reintentar'),
              ),
            ],
          ),
        ),
      );
    }

    return Center(
      child: SingleChildScrollView(
        padding: const EdgeInsets.all(16),
        child: ConstrainedBox(
          constraints: const BoxConstraints(maxWidth: 420),
          child: Form(
            key: _formulario,
            child: Column(
              mainAxisSize: MainAxisSize.min,
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                // TG-189 (A11): mientras no confirme su correo no puede
                // enviar pedidos. Aquí está el botón al que lo manda el aviso
                // del servidor cuando lo intenta.
                if (!auth.emailVerificado) ...[
                  FpTarjeta(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Row(
                          children: const [
                            Icon(Icons.mark_email_unread_outlined, color: Color(0xFFB45309)),
                            SizedBox(width: 8),
                            Expanded(
                              child: Text(
                                'Falta confirmar tu correo',
                                style: TextStyle(
                                  color: Color(0xFFB45309),
                                  fontSize: 15,
                                  fontWeight: FontWeight.w600,
                                ),
                              ),
                            ),
                          ],
                        ),
                        const SizedBox(height: 6),
                        const Text(
                          'Te mandamos un enlace cuando te dieron la cuenta. Ábrelo para poder '
                          'enviar pedidos y pagar. Si no lo encuentras, revisa la carpeta de spam '
                          'o pide otro aquí.',
                          style: TextStyle(fontSize: 13.5, height: 1.35),
                        ),
                        const SizedBox(height: 12),
                        OutlinedButton.icon(
                          onPressed: _reenviando ? null : _reenviarConfirmacion,
                          style: OutlinedButton.styleFrom(minimumSize: const Size.fromHeight(48)),
                          icon: _reenviando
                              ? const SizedBox(
                                  height: 18,
                                  width: 18,
                                  child: CircularProgressIndicator(strokeWidth: 2),
                                )
                              : const Icon(Icons.send_outlined),
                          label: Text(_reenviando ? 'Enviando…' : 'Mandarme otro enlace'),
                        ),
                      ],
                    ),
                  ),
                  const SizedBox(height: 16),
                ],
                FpTarjeta(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: [
                      Row(
                        children: [
                          FpAvatar(nombre: _usuario!.nombre, fotoUrl: _usuario!.fotoUrl, tamano: 56),
                          const SizedBox(width: 12),
                          Expanded(
                            child: Column(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: [
                                const Text(
                                  'Mis datos',
                                  style: TextStyle(
                                    color: FpColores.sidebar,
                                    fontSize: 16,
                                    fontWeight: FontWeight.w600,
                                  ),
                                ),
                                TextButton.icon(
                                  onPressed: _subiendoFoto || _guardando ? null : _cambiarFoto,
                                  style: TextButton.styleFrom(
                                    padding: EdgeInsets.zero,
                                    minimumSize: const Size(48, 40),
                                  ),
                                  icon: _subiendoFoto
                                      ? const SizedBox(
                                          height: 16,
                                          width: 16,
                                          child: CircularProgressIndicator(strokeWidth: 2),
                                        )
                                      : const Icon(Icons.photo_camera_outlined, size: 18),
                                  label: Text(_subiendoFoto ? 'Subiendo…' : 'Cambiar foto'),
                                ),
                              ],
                            ),
                          ),
                        ],
                      ),
                      if (esRevendedor && _usuario!.cambiosPendientes.isNotEmpty) ...[
                        const SizedBox(height: 12),
                        _AvisoPendiente(usuario: _usuario!),
                      ],
                      const SizedBox(height: 16),
                      TextFormField(
                        initialValue: _usuario!.email,
                        readOnly: true,
                        enabled: false,
                        decoration: InputDecoration(
                          labelText: 'Correo',
                          helperText: esRevendedor
                              ? 'Para cambiarlo te mandamos un enlace de confirmación.'
                              : 'El correo no se puede cambiar.',
                          prefixIcon: const Icon(Icons.mail_outline),
                        ),
                      ),
                      if (esRevendedor)
                        Align(
                          alignment: Alignment.centerRight,
                          child: TextButton(
                            onPressed: _guardando ? null : () => _pedirCambio(campo: 'email'),
                            child: const Text('Cambiar correo'),
                          ),
                        ),
                      const SizedBox(height: 16),
                      TextFormField(
                        controller: _nombre,
                        enabled: !_guardando,
                        decoration: const InputDecoration(
                          labelText: 'Nombre',
                          prefixIcon: Icon(Icons.person_outline),
                        ),
                        textCapitalization: TextCapitalization.words,
                        textInputAction: TextInputAction.next,
                        validator: _validarNombre,
                        onChanged: (_) => _limpiarErrorServidor('nombre'),
                        forceErrorText: _erroresServidor['nombre']?.first,
                      ),
                      const SizedBox(height: 16),
                      if (esRevendedor) ...[
                        TextFormField(
                          key: ValueKey('telefono-${_usuario!.telefono}'),
                          initialValue: _usuario!.telefono ?? '',
                          enabled: false,
                          decoration: const InputDecoration(
                            labelText: 'Teléfono',
                            helperText: 'Para cambiarlo te mandamos un enlace de confirmación.',
                            prefixIcon: Icon(Icons.phone_outlined),
                          ),
                        ),
                        Align(
                          alignment: Alignment.centerRight,
                          child: TextButton(
                            onPressed: _guardando ? null : () => _pedirCambio(campo: 'telefono'),
                            child: const Text('Cambiar teléfono'),
                          ),
                        ),
                      ] else
                      TextFormField(
                        controller: _telefono,
                        enabled: !_guardando,
                        decoration: const InputDecoration(
                          labelText: 'Teléfono (opcional)',
                          prefixIcon: Icon(Icons.phone_outlined),
                        ),
                        keyboardType: TextInputType.phone,
                        textInputAction: TextInputAction.done,
                        onFieldSubmitted: (_) => _guardar(),
                        validator: _validarTelefono,
                        onChanged: (_) => _limpiarErrorServidor('telefono'),
                        forceErrorText: _erroresServidor['telefono']?.first,
                      ),
                      if (_errorGuardar != null) ...[
                        const SizedBox(height: 16),
                        AvisoError(mensaje: _errorGuardar!),
                      ],
                      const SizedBox(height: 24),
                      FilledButton(
                        onPressed: _guardando ? null : _guardar,
                        style: FilledButton.styleFrom(minimumSize: const Size.fromHeight(52)),
                        child: _guardando
                            ? const SizedBox(
                                height: 20,
                                width: 20,
                                child: CircularProgressIndicator(strokeWidth: 2),
                              )
                            : const Text('Guardar cambios'),
                      ),
                    ],
                  ),
                ),
                const SizedBox(height: 20),
                // TG-281: esto estaba en el Inicio. Aqui solo van el rol y la
                // distribuidora: el nombre y el correo ya estan arriba, en el
                // formulario, y repetirlos solo confunde.
                const FpTituloSeccion('Tu cuenta'),
                FpTarjeta(
                  child: Column(
                    children: [
                      _Dato(etiqueta: 'Rol', valor: InicioScreen.nombreRol(auth.rol)),
                      _Dato(
                        etiqueta: 'Distribuidora',
                        // Solo el nombre real. Antes, si no llegaba, se caía
                        // al número y se veía «Distribuidora 1»; un número
                        // no le dice nada a nadie, mejor el dato vacío.
                        valor: auth.distribuidoraNombre,
                        ultimo: true,
                      ),
                    ],
                  ),
                ),
                const SizedBox(height: 20),
                const FpTituloSeccion('Seguridad'),
                OutlinedButton.icon(
                  onPressed: _guardando ? null : _abrirCambiarPassword,
                  style: OutlinedButton.styleFrom(minimumSize: const Size.fromHeight(52)),
                  icon: const Icon(Icons.lock_outline),
                  label: const Text('Cambiar contraseña'),
                ),
                const SizedBox(height: 10),
                // TG-281: estaba al final del Inicio. Ahora el Inicio enseña
                // el resumen y la navegacion vive en la barra de abajo, asi
                // que cerrar sesion va aqui, que es donde se busca.
                OutlinedButton.icon(
                  onPressed: _guardando ? null : () => context.read<AuthProvider>().logout(),
                  style: OutlinedButton.styleFrom(
                    foregroundColor: FpColores.peligro,
                    side: BorderSide(color: FpColores.peligro.withValues(alpha: 0.3)),
                    minimumSize: const Size.fromHeight(52),
                  ),
                  icon: const Icon(Icons.logout_rounded),
                  label: const Text('Cerrar sesión'),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

/// La foto que eligió la persona, ya en memoria.
class FotoElegida {
  const FotoElegida({required this.bytes, required this.nombre});

  final List<int> bytes;
  final String nombre;
}

/// Lo que falta confirmar por correo.
class _AvisoPendiente extends StatelessWidget {
  const _AvisoPendiente({required this.usuario});

  final Usuario usuario;

  @override
  Widget build(BuildContext context) {
    final lineas = [
      if (usuario.correoPendiente != null) 'Correo nuevo: ${usuario.correoPendiente}',
      if (usuario.telefonoPendiente != null) 'Teléfono nuevo: ${usuario.telefonoPendiente}',
    ];

    return Semantics(
      liveRegion: true,
      child: Container(
        padding: const EdgeInsets.all(12),
        decoration: BoxDecoration(
          color: const Color(0xFFFFFBEB),
          borderRadius: BorderRadius.circular(10),
          border: Border.all(color: const Color(0xFFFCD34D)),
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            const Text(
              'Pendiente de confirmar',
              style: TextStyle(color: Color(0xFFB45309), fontWeight: FontWeight.w600),
            ),
            const SizedBox(height: 4),
            for (final linea in lineas) Text(linea, style: const TextStyle(fontSize: 13.5)),
            const SizedBox(height: 4),
            const Text(
              'Abre el enlace que mandamos a tu correo. Hasta entonces sigues usando tus datos actuales.',
              style: TextStyle(fontSize: 12.5, height: 1.35),
            ),
          ],
        ),
      ),
    );
  }
}

/// Pide el correo o el teléfono nuevo y lo manda al servidor. Regresa el
/// usuario actualizado, o null si se canceló.
class _DialogoCambioContacto extends StatefulWidget {
  const _DialogoCambioContacto({required this.api, required this.esCorreo, required this.actual});

  final ApiService api;
  final bool esCorreo;
  final String actual;

  @override
  State<_DialogoCambioContacto> createState() => _DialogoCambioContactoState();
}

class _DialogoCambioContactoState extends State<_DialogoCambioContacto> {
  final _valor = TextEditingController();
  bool _enviando = false;
  String? _error;

  String get _campo => widget.esCorreo ? 'email' : 'telefono';

  @override
  void dispose() {
    _valor.dispose();
    super.dispose();
  }

  Future<void> _enviar() async {
    final valor = _valor.text.trim();
    if (valor.isEmpty) {
      setState(() => _error = widget.esCorreo ? 'Escribe el correo nuevo.' : 'Escribe el teléfono nuevo.');
      return;
    }

    setState(() {
      _enviando = true;
      _error = null;
    });

    try {
      final respuesta = await widget.api.post(
        widget.esCorreo ? 'perfil/correo' : 'perfil/telefono',
        cuerpo: {_campo: valor},
      );
      if (!mounted) return;
      Navigator.of(context).pop(Usuario.desdeJson(respuesta['data'] as Map<String, dynamic>));
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _enviando = false;
        _error = e.errores[_campo]?.first ?? e.mensaje;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    return AlertDialog(
      title: Text(widget.esCorreo ? 'Cambiar correo' : 'Cambiar teléfono'),
      content: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            widget.esCorreo
                ? 'Te mandaremos un enlace al correo nuevo. El cambio se hace cuando lo abras; '
                    'a tu correo actual le llega un aviso.'
                : 'Te mandaremos un enlace a tu correo para confirmar el teléfono nuevo.',
            style: const TextStyle(fontSize: 13.5, height: 1.35),
          ),
          if (widget.actual.isNotEmpty) ...[
            const SizedBox(height: 8),
            Text('Actual: ${widget.actual}', style: const TextStyle(fontSize: 13)),
          ],
          const SizedBox(height: 12),
          TextField(
            controller: _valor,
            autofocus: true,
            enabled: !_enviando,
            keyboardType: widget.esCorreo ? TextInputType.emailAddress : TextInputType.phone,
            autofillHints: [widget.esCorreo ? AutofillHints.email : AutofillHints.telephoneNumber],
            decoration: InputDecoration(
              labelText: widget.esCorreo ? 'Correo nuevo' : 'Teléfono nuevo',
              errorText: _error,
            ),
            onSubmitted: (_) => _enviar(),
          ),
        ],
      ),
      actions: [
        TextButton(
          onPressed: _enviando ? null : () => Navigator.of(context).pop(),
          child: const Text('Cancelar'),
        ),
        FilledButton(
          onPressed: _enviando ? null : _enviar,
          child: Text(_enviando ? 'Enviando…' : 'Mandar enlace'),
        ),
      ],
    );
  }
}

/// Un renglón de dato que solo se lee, como el de la tabla del panel web.
/// Venía del Inicio (TG-165); se mudó aquí con TG-281.
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
