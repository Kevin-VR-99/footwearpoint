import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../models/usuario.dart';
import '../providers/auth_provider.dart';
import '../services/api_service.dart';
import 'cambiar_password_screen.dart';
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
class PerfilScreen extends StatefulWidget {
  const PerfilScreen({super.key});

  @override
  State<PerfilScreen> createState() => _PerfilScreenState();
}

class _PerfilScreenState extends State<PerfilScreen> {
  final _formulario = GlobalKey<FormState>();
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
    } on ApiException catch (e) {
      // Un 401 lo atiende AuthProvider (regresa al login). Lo demás se
      // muestra aquí con opción de reintentar.
      if (!mounted) return;
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
        cuerpo: {'nombre': _nombre.text.trim(), 'telefono': _telefono.text.trim()},
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

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Mi perfil'), bottom: const FpBordeMarca()),
      body: SafeArea(child: _contenido(context)),
    );
  }

  Widget _contenido(BuildContext context) {
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
                FpTarjeta(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: [
                      Row(
                        children: [
                          FpAvatar(nombre: _usuario!.nombre, tamano: 32),
                          const SizedBox(width: 10),
                          const Text(
                            'Mis datos',
                            style: TextStyle(
                              color: FpColores.sidebar,
                              fontSize: 16,
                              fontWeight: FontWeight.w600,
                            ),
                          ),
                        ],
                      ),
                      const SizedBox(height: 16),
                      TextFormField(
                        initialValue: _usuario!.email,
                        readOnly: true,
                        enabled: false,
                        decoration: const InputDecoration(
                          labelText: 'Correo',
                          helperText: 'El correo no se puede cambiar.',
                          prefixIcon: Icon(Icons.mail_outline),
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
                const FpTituloSeccion('Seguridad'),
                OutlinedButton.icon(
                  onPressed: _guardando ? null : _abrirCambiarPassword,
                  style: OutlinedButton.styleFrom(minimumSize: const Size.fromHeight(52)),
                  icon: const Icon(Icons.lock_outline),
                  label: const Text('Cambiar contraseña'),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}
