import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../providers/auth_provider.dart';
import '../services/api_service.dart';
import '../validaciones.dart';
import 'login_screen.dart';

/// Pedir el enlace para cambiar la contraseña (E1-02).
///
/// La app solo pide el correo. El cambio de contraseña se termina en la
/// página web a la que lleva el enlace del correo (/reset-password/{token}),
/// que es la que exige las reglas mínimas de la nueva contraseña.
///
/// No usa el error/ocupado de AuthProvider (se mezclaría con los mensajes del
/// login): lleva su propio estado y solo toma prestado el ApiService.
class RecuperarPasswordScreen extends StatefulWidget {
  const RecuperarPasswordScreen({super.key, this.correoInicial = ''});

  /// El correo que ya se había escrito en el login, si había alguno.
  final String correoInicial;

  /// Es el mismo texto exista o no una cuenta con ese correo: decir "no
  /// existe" le serviría a cualquiera para averiguar qué correos están
  /// registrados.
  static const mensajeEnviado =
      'Si el correo pertenece a una cuenta, te llegará un enlace para crear una nueva contraseña.';

  @override
  State<RecuperarPasswordScreen> createState() => _RecuperarPasswordScreenState();
}

class _RecuperarPasswordScreenState extends State<RecuperarPasswordScreen> {
  final _formulario = GlobalKey<FormState>();
  late final _email = TextEditingController(text: widget.correoInicial);

  bool _enviando = false;
  String? _error;

  /// El correo al que se mandó el enlace. Mientras sea null se ve el
  /// formulario; cuando tiene valor se ve "Revisa tu bandeja".
  String? _correoEnviado;

  @override
  void dispose() {
    _email.dispose();
    super.dispose();
  }

  Future<void> _enviar() async {
    if (!_formulario.currentState!.validate()) return;

    FocusScope.of(context).unfocus();

    final correo = _email.text.trim();
    final api = context.read<AuthProvider>().api;

    setState(() {
      _enviando = true;
      _error = null;
    });

    String? enviado;
    String? error;

    try {
      await api.post('auth/forgot-password', cuerpo: {'email': correo});
      enviado = correo;
    } on ApiException catch (e) {
      if (e.codigoHttp == 422 && e.errores.isEmpty) {
        // Un 422 sin errores de validación es el broker de Laravel diciendo
        // "ese correo no tiene cuenta" o "ya pediste uno hace menos de un
        // minuto". Los dos se muestran igual que un envío exitoso, para no
        // delatar qué correos existen (decisión con Kevin: el backend
        // también va a responder siempre 200).
        enviado = correo;
      } else {
        // Correo mal escrito (viene en errors.email), sin conexión o error
        // del servidor: eso sí hay que decirlo, no revela nada.
        error = e.errorDe('email') ?? e.mensaje;
      }
    }

    if (!mounted) return;

    setState(() {
      _enviando = false;
      _correoEnviado = enviado;
      _error = error;
    });
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Recuperar contraseña')),
      body: SafeArea(
        child: Center(
          child: SingleChildScrollView(
            padding: const EdgeInsets.all(24),
            child: ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: 420),
              child: _correoEnviado == null
                  ? _formularioCorreo(context)
                  : _enviadoCorreo(context, _correoEnviado!),
            ),
          ),
        ),
      ),
    );
  }

  Widget _formularioCorreo(BuildContext context) {
    final tema = Theme.of(context);

    return Form(
      key: _formulario,
      child: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Icon(Icons.lock_reset, size: 64, color: tema.colorScheme.primary),
          const SizedBox(height: 16),
          Text(
            'Escribe el correo de tu cuenta y te mandaremos un enlace para crear una nueva contraseña.',
            textAlign: TextAlign.center,
            style: tema.textTheme.bodyLarge?.copyWith(
              color: tema.colorScheme.onSurfaceVariant,
            ),
          ),
          const SizedBox(height: 32),
          TextFormField(
            controller: _email,
            enabled: !_enviando,
            decoration: const InputDecoration(
              labelText: 'Correo',
              prefixIcon: Icon(Icons.mail_outline),
              border: OutlineInputBorder(),
            ),
            keyboardType: TextInputType.emailAddress,
            autofillHints: const [AutofillHints.email],
            autocorrect: false,
            textInputAction: TextInputAction.done,
            onFieldSubmitted: (_) => _enviar(),
            validator: Validaciones.correo,
          ),
          if (_error != null) ...[
            const SizedBox(height: 16),
            AvisoError(mensaje: _error!),
          ],
          const SizedBox(height: 24),
          FilledButton(
            onPressed: _enviando ? null : _enviar,
            style: FilledButton.styleFrom(minimumSize: const Size.fromHeight(52)),
            child: _enviando
                ? const SizedBox(
                    height: 20,
                    width: 20,
                    child: CircularProgressIndicator(strokeWidth: 2),
                  )
                : const Text('Enviar enlace'),
          ),
        ],
      ),
    );
  }

  Widget _enviadoCorreo(BuildContext context, String correo) {
    final tema = Theme.of(context);
    final textoSecundario = tema.textTheme.bodyMedium?.copyWith(
      color: tema.colorScheme.onSurfaceVariant,
    );

    return Column(
      mainAxisSize: MainAxisSize.min,
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Icon(Icons.mark_email_read_outlined, size: 64, color: tema.colorScheme.primary),
        const SizedBox(height: 16),
        Text(
          'Revisa tu bandeja',
          textAlign: TextAlign.center,
          style: tema.textTheme.titleLarge?.copyWith(fontWeight: FontWeight.bold),
        ),
        const SizedBox(height: 12),
        Text(
          RecuperarPasswordScreen.mensajeEnviado,
          textAlign: TextAlign.center,
          style: tema.textTheme.bodyLarge,
        ),
        const SizedBox(height: 8),
        Text(
          correo,
          textAlign: TextAlign.center,
          style: tema.textTheme.bodyLarge?.copyWith(fontWeight: FontWeight.w600),
        ),
        const SizedBox(height: 16),
        // 60 minutos: 'expire' del broker 'users' en config/auth.php.
        Text(
          'El enlace vence en 60 minutos. Ábrelo desde tu teléfono y ahí mismo '
          'crea tu nueva contraseña. Si no lo ves, revisa la carpeta de spam.',
          textAlign: TextAlign.center,
          style: textoSecundario,
        ),
        const SizedBox(height: 32),
        FilledButton(
          onPressed: () => Navigator.of(context).pop(),
          style: FilledButton.styleFrom(minimumSize: const Size.fromHeight(52)),
          child: const Text('Volver al login'),
        ),
      ],
    );
  }
}
