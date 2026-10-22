import 'dart:async';
import 'dart:convert';

import 'package:flutter/foundation.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:firebase_messaging/firebase_messaging.dart';

import '../models/usuario.dart';
import '../services/api_service.dart';
import '../services/aperturas_push.dart';
import '../services/avisos_en_vivo.dart';
import '../services/enlaces_app.dart';
import '../services/registro_fcm.dart';

/// Guarda quién inició sesión y avisa a las pantallas cuando eso cambia.
///
/// Las pantallas no guardan el usuario por su cuenta: lo leen de aquí.
class AuthProvider extends ChangeNotifier {
  AuthProvider({ApiService? api}) : api = api ?? ApiService() {
    this.api.alNoAutorizado = _sesionVencida;
    EnlacesApp.haySesion = () => haySesion;
  }

  /// Se expone para que las demás pantallas hagan sus peticiones con el
  /// mismo token, sin crear su propio ApiService.
  final ApiService api;

  /// En las pruebas de widget el reloj es falso y no avanza solo. Si el login
  /// dispara los reintentos de FCM (2 s, 4 s, 8 s, 16 s, 32 s), la prueba se
  /// queda esperando. Las pruebas lo apagan en test/flutter_test_config.dart.
  /// La app real lo deja en true: el login no espera a que termine.
  @visibleForTesting
  static bool registrarPush = true;

  /// Registro del token de Firebase con reintentos (ver [RegistroFcm]).
  late final registroFcm = RegistroFcm(
    enviar: (token) => api.post(
      'dispositivos-fcm',
      cuerpo: {'token': token, 'plataforma': _plataformaFcm},
    ),
  );

  /// La columna acepta android, ios y web; antes siempre se mandaba android.
  static String get _plataformaFcm {
    if (kIsWeb) return 'web';
    return defaultTargetPlatform == TargetPlatform.iOS ? 'ios' : 'android';
  }

  static const mensajeSesionVencida = 'Tu sesión expiró. Vuelve a iniciar sesión.';

  /// Llave donde una versión anterior de esta rama guardaba usuario, rol y
  /// distribuidora en el teléfono. Ya no se guarda nada ahí (ver
  /// [restaurarSesion]); solo se borra por si quedó en algún teléfono de prueba.
  static const _llaveSesionVieja = 'sesion_usuario';

  /// TG-281: la ultima sesion buena, guardada en el telefono para poder
  /// abrir la app sin internet.
  ///
  /// Hasta ahora, a proposito, en el telefono solo vivia el token: si los
  /// datos se guardan y a alguien le suspenden la cuenta, la app seguiria
  /// creyendo que tiene acceso. Eso sigue siendo verdad, asi que la copia
  /// se usa SOLO cuando el servidor no contesta, se reemplaza en cuanto
  /// contesta, y se borra al cerrar sesion o en cuanto el servidor rechaza
  /// el token. Sin servidor no se puede hacer nada igualmente: ni enviar un
  /// pedido, ni pagar, ni ver el catalogo.
  static const _llaveSesionCache = 'sesion_cache';

  final _almacen = const FlutterSecureStorage();

  /// El escuchador de renovación del token de Firebase (E16-03). Uno solo
  /// para toda la sesión: ver [_registrarDispositivoFCM].
  StreamSubscription<String>? _renovacionTokenFcm;

  Usuario? _usuario;
  String? _rol;
  int? _distribuidoraId;

  /// E1-04: aceptó el aviso de privacidad y los términos. Mientras no, la
  /// app solo le muestra el aviso (ver _Raiz en main.dart).
  bool _legalesAceptados = false;

  /// TG-193: la distribuidora le puso una contraseña temporal y tiene que
  /// cambiarla antes de poder usar la app.
  bool _debeCambiarPassword = false;

  /// TG-189 (A11): mientras no confirme su correo no puede enviar pedidos ni
  /// pagar. No se le saca de la app: solo se le avisa y se le ofrece pedir
  /// otro enlace.
  bool _emailVerificado = true;

  /// TG-281: antes se enseñaba el numero de la distribuidora, que no le dice
  /// nada a nadie. El servidor ya manda el nombre.
  String? _distribuidoraNombre;

  /// La sesion tal como se guardo en el telefono.
  ///
  /// Hace falta tenerla a mano porque algunas cosas cambian DESPUES de
  /// guardarla —aceptar los legales, cambiar la contrasena temporal,
  /// confirmar el correo, editar el perfil— y si la copia no se entera, al
  /// abrir la app sin internet vuelve a pedir lo que ya se hizo.
  Map<String, dynamic>? _copiaSesion;
  bool _iniciando = true;
  bool _sinConexion = false;

  /// True cuando la app se abrio con la copia guardada porque el servidor no
  /// contestaba. Lo que se ve puede estar viejo, y hay que decirlo.
  bool _conDatosGuardados = false;
  bool _ocupado = false;
  String? _error;

  Usuario? get usuario => _usuario;
  String? get rol => _rol;
  int? get distribuidoraId => _distribuidoraId;
  bool get legalesAceptados => _legalesAceptados;
  bool get debeCambiarPassword => _debeCambiarPassword;
  bool get emailVerificado => _emailVerificado;

  /// TG-281: el nombre de la distribuidora, para enseñarlo en Mi perfil.
  /// Null en versiones del servidor que todavia no lo mandan.
  String? get distribuidoraNombre => _distribuidoraNombre;
  bool get ocupado => _ocupado;
  String? get error => _error;
  bool get haySesion => _usuario != null;

  /// La cuenta es válida pero no tiene distribuidora: su afiliación está
  /// suspendida (revendedor_distribuidora.estado) o la cuenta quedó mal
  /// ligada. El backend ya no le deja ver nada; la app tampoco la deja pasar.
  /// No se borra el token: si la reactivan, basta con volver a revisar.
  bool get sinAcceso => haySesion && _distribuidoraId == null;

  /// True mientras se revisa, al abrir la app, si el token guardado sirve.
  /// Mientras dure, no se sabe todavía si toca mostrar el login o la app.
  bool get iniciando => _iniciando;

  /// True si al abrir la app había token pero no se pudo hablar con el
  /// servidor para revisarlo. El token NO se borra: puede ser solo que no
  /// hay WiFi, y se vuelve a intentar con [reintentar].
  bool get sinConexion => _sinConexion;

  /// True si la app esta funcionando con la copia guardada en el telefono.
  bool get conDatosGuardados => _conDatosGuardados;

  /// Se llama una sola vez al abrir la app.
  ///
  /// En el teléfono solo vive el token. Usuario, rol y distribuidora se le
  /// piden al servidor con GET auth/me: si se guardaran en el teléfono y un
  /// admin suspendiera a alguien, la app seguiría creyendo que tiene acceso.
  Future<void> restaurarSesion() async {
    try {
      await _almacen.delete(key: _llaveSesionVieja);

      final token = await api.token();
      if (token == null || token.isEmpty) return;

      final respuesta = await api.get('auth/me');
      _llenarSesion(respuesta['data'] as Map<String, dynamic>);
      _conDatosGuardados = false;
      await _guardarCopia(respuesta['data'] as Map<String, dynamic>);
      _registrarDispositivoFCM();
    } on ApiException catch (e) {
      if (e.codigoHttp == null) {
        // Sin conexión o el servidor tardó: no se sabe si el token sirve.
        //
        // TG-281: antes aqui se quedaba en una pantalla de "no se pudo
        // conectar" y no se podia hacer nada. Si hay una copia de la ultima
        // sesion, se abre la app con ella: el inicio y el perfil se pueden
        // ver sin internet. Lo demas seguira fallando, porque necesita al
        // servidor.
        final copia = await _leerCopia();

        if (copia != null) {
          _llenarSesion(copia);
          _conDatosGuardados = true;
          _error = null;
        } else {
          _sinConexion = true;
          _error = e.mensaje;
        }
      } else {
        // El servidor sí contestó y no reconoció el token: 401 (vencido o
        // cuenta suspendida) o cualquier otro error (404, 500).
        await _olvidarSesion();
        _error = e.codigoHttp == 401 ? mensajeSesionVencida : null;
      }
    } catch (_) {
      // El servidor contestó 200 pero con una forma que no se esperaba. No
      // se puede armar la sesión con eso: se pide login de nuevo.
      await _olvidarSesion();
    } finally {
      _iniciando = false;
      notifyListeners();
      if (haySesion) EnlacesApp.abrirSiHaySesion();
    }
  }

  /// El botón "Reintentar" de la pantalla sin conexión.
  Future<void> reintentar() async {
    _sinConexion = false;
    _conDatosGuardados = false;
    _iniciando = true;
    _error = null;
    notifyListeners();

    await restaurarSesion();
  }

  /// Devuelve true si entró. Si no, el motivo queda en [error].
  Future<bool> login(String email, String password) async {
    _ocupado = true;
    _error = null;
    notifyListeners();

    try {
      final respuesta = await api.post(
        'auth/login',
        // Auditoría 21-oct-2026: el servidor nombra el token con el dispositivo.
        cuerpo: {'email': email, 'password': password, 'device_name': 'App FootwearPoint ($_plataformaFcm)'},
      );

      final datos = respuesta['data'] as Map<String, dynamic>;

      await api.guardarToken(datos['token'] as String);
      _llenarSesion(datos);
      _conDatosGuardados = false;
      await _guardarCopia(datos);
      _registrarDispositivoFCM();
      EnlacesApp.abrirSiHaySesion();

      return true;
    } on ApiException catch (e) {
      // Laravel manda el "credenciales incorrectas" dentro de errors.email,
      // no en message. Si no viene ahí, se muestra el mensaje general.
      _error = e.errorDe('email') ?? e.mensaje;
      return false;
    } finally {
      _ocupado = false;
      notifyListeners();
    }
  }

  Future<void> logout() async {
    _ocupado = true;
    notifyListeners();

    try {
      // Lo indispensable: avisarle SIEMPRE al servidor, para que invalide el
      // token. Desde TG-144 el servidor además quita este celular de las
      // notificaciones al cerrar la sesión, así que no hace falta mandarle el
      // token de Firebase. Va primero y aparte de Firebase: si Firebase
      // fallara antes, el servidor nunca se enteraría y la sesión seguiría
      // válida allá (E16-03, corrección TG-161).
      try {
        await api.post('auth/logout');
      } on ApiException {
        // Aunque el servidor no conteste, la sesión local se cierra igual: no
        // tiene caso dejar al usuario atrapado dentro de la app.
      }

      // Extra: que Firebase también olvide el token en este teléfono. Si falla
      // (sin internet, sin servicios de Google), no impide cerrar la sesión.
      await _renovacionTokenFcm?.cancel();
      _renovacionTokenFcm = null;
      registroFcm.detener();
      unawaited(AperturasPush.detener());
      unawaited(AvisosEnVivo.detener());
      EnlacesApp.olvidar();

      try {
        await FirebaseMessaging.instance.deleteToken();
      } catch (e) {
        debugPrint('No se pudo borrar el token de Firebase: $e');
      }
    } finally {
      await _olvidarSesion();

      _error = null;
      _ocupado = false;

      notifyListeners();
    }
  }

  @override
  void dispose() {
    _renovacionTokenFcm?.cancel();
    registroFcm.detener();
    super.dispose();
  }

  /// Reemplaza los datos del usuario con los que regresó el servidor (por
  /// ejemplo, al guardar el perfil), para que el nombre nuevo se vea en toda
  /// la app sin volver a iniciar sesión. Rol y distribuidora no cambian.
  void actualizarUsuario(Usuario usuario) {
    if (!haySesion) return;

    _usuario = usuario;
    _corregirCopia('usuario', usuario.aJson());
    notifyListeners();
  }

  /// Lo llama ApiService cuando el servidor responde 401, desde cualquier
  /// pantalla. Al quedar sin usuario, main.dart muestra el login solo.
  ///
  /// NO llama a [logout]: logout le pega al servidor, que respondería 401
  /// otra vez y se ciclaría.
  void _sesionVencida() {
    // Sin sesión no hay nada que cerrar (por ejemplo, el 401 de auth/me al
    // abrir la app, que ya atiende restaurarSesion).
    if (!haySesion) return;

    unawaited(_olvidarSesion());

    _error = mensajeSesionVencida;
    notifyListeners();
  }

  /// Misma forma en el login y en auth/me: { usuario, rol, distribuidora_id,
  /// legales_aceptados, debe_cambiar_password }.
  /// Guarda la ultima sesion buena. Si fallara al escribir, no pasa nada:
  /// solo significa que la proxima vez sin internet no se podra abrir.
  Future<void> _guardarCopia(Map<String, dynamic> datos) async {
    _copiaSesion = datos;

    try {
      await _almacen.write(key: _llaveSesionCache, value: jsonEncode(datos));
    } catch (_) {
      // Sin copia, pero con la sesion en pie.
    }
  }

  /// Corrige un dato suelto de la copia guardada.
  ///
  /// Sin esto, la copia se queda con la foto del momento del login: alguien
  /// que ya acepto los legales volveria a verlos al abrir la app sin
  /// internet, y al darle "Aceptar" se toparia con que no hay conexion.
  void _corregirCopia(String clave, Object? valor) {
    final copia = _copiaSesion;

    if (copia == null) return;

    copia[clave] = valor;
    unawaited(_guardarCopia(copia));
  }

  /// La ultima sesion guardada, o null si no hay o si quedo ilegible.
  Future<Map<String, dynamic>?> _leerCopia() async {
    try {
      final texto = await _almacen.read(key: _llaveSesionCache);
      if (texto == null || texto.isEmpty) return null;

      final datos = jsonDecode(texto);

      if (datos is! Map<String, dynamic>) return null;

      _copiaSesion = datos;

      return datos;
    } catch (_) {
      // Si quedo a medias o de una version vieja, se ignora: es preferible
      // pedir login a abrir la app con algo que no se entiende.
      return null;
    }
  }

  void _llenarSesion(Map<String, dynamic> datos) {
    // Se lee todo antes de asignar, para no quedar con media sesión si algún
    // campo no viene como se esperaba.
    final usuario = Usuario.desdeJson(datos['usuario'] as Map<String, dynamic>);
    final rol = datos['rol'] as String?;
    final distribuidoraId = datos['distribuidora_id'] as int?;
    // E1-04: si el servidor no manda la llave, se asume que no los aceptó.
    final legalesAceptados = datos['legales_aceptados'] as bool? ?? false;
    // Si el servidor no manda la llave (versión vieja), se asume que no.
    final debeCambiar = datos['debe_cambiar_password'] as bool? ?? false;
    // Si el servidor no manda la llave (version vieja), se asume confirmado
    // para no inventarle un problema a nadie.
    final verificado = datos['email_verificado'] as bool? ?? true;

    _usuario = usuario;
    _rol = rol;
    _distribuidoraId = distribuidoraId;
    _legalesAceptados = legalesAceptados;
    _debeCambiarPassword = debeCambiar;
    _emailVerificado = verificado;
    _distribuidoraNombre = datos['distribuidora'] as String?;
  }

  /// TG-193: la acaba de cambiar desde la pantalla obligatoria, así que ya
  /// puede usar la app sin tener que volver a preguntarle al servidor.
  void confirmarPasswordCambiada() {
    if (!_debeCambiarPassword) return;

    _debeCambiarPassword = false;
    _corregirCopia('debe_cambiar_password', false);
    notifyListeners();
  }

  /// E1-04: el servidor ya registró la aceptación (auth/aceptar-legales),
  /// así que la app pasa sola al inicio sin volver a preguntarle.
  void confirmarLegalesAceptados() {
    if (_legalesAceptados) return;

    _legalesAceptados = true;
    _corregirCopia('legales_aceptados', true);
    notifyListeners();
  }

  /// TG-189 (A11): "no me llegó el correo". El servidor siempre responde lo
  /// mismo, haya mandado algo o no, para no delatar el estado de la cuenta.
  ///
  /// Devuelve el mensaje que hay que enseñar.
  Future<String> reenviarVerificacion() async {
    final respuesta = await api.post('auth/reenviar-verificacion');

    return respuesta['message'] as String? ??
        'Si tu correo está sin confirmar, te mandamos un enlace nuevo.';
  }

  /// TG-189 (A11): vuelve a preguntarle al servidor si el correo ya está
  /// confirmado.
  ///
  /// Hace falta porque la persona confirma FUERA de la app: abre el enlace en
  /// su correo y regresa. Sin esto, el aviso se quedaría puesto hasta que
  /// cerrara y volviera a abrir la app, y creería que no sirvió de nada.
  ///
  /// Si el servidor no contesta no pasa nada: se queda como estaba.
  Future<void> revisarCorreoConfirmado() async {
    if (!haySesion || _emailVerificado) return;

    try {
      final respuesta = await api.get('auth/me');
      final datos = respuesta['data'] as Map<String, dynamic>;
      final verificado = datos['email_verificado'] as bool? ?? true;

      if (verificado == _emailVerificado) return;

      _emailVerificado = verificado;
      _corregirCopia('email_verificado', verificado);
      notifyListeners();
    } on ApiException {
      // Sin conexión o el servidor falló: el aviso se queda, que es lo seguro.
    }
  }

  /// Borra la sesión de memoria y el token del teléfono.
  Future<void> _olvidarSesion() async {
    // Primero la memoria, sin esperar a nada: así [haySesion] cambia en ese
    // mismo instante, aunque borrar del teléfono tarde un poco.
    _usuario = null;
    _rol = null;
    _distribuidoraId = null;
    _legalesAceptados = false;
    _debeCambiarPassword = false;
    _emailVerificado = true;
    _distribuidoraNombre = null;
    _conDatosGuardados = false;
    _copiaSesion = null;

    // TG-281: la copia se va con la sesion. Da igual si fue la persona
    // cerrando sesion o el servidor rechazando el token: en los dos casos
    // esos datos ya no valen y no tienen por que seguir en el telefono.
    await _almacen.delete(key: _llaveSesionCache);
    await api.borrarToken();
  }

  /// Pide el token a Firebase y lo registra en Laravel (TG-96).
  ///
  /// No se espera: el login ya terminó. Los reintentos tienen tope en
  /// [RegistroFcm] (5 intentos, espera hasta 32 s).
  void _registrarDispositivoFCM() {
    if (!registrarPush) return;
    unawaited(_registrarDispositivoFcmEnSegundoPlano());
  }

  Future<void> _registrarDispositivoFcmEnSegundoPlano() async {
    // 2. Escuchamos por si Firebase decide renovar el token en segundo plano.
    // Va primero, aparte del permiso y del getToken: si esos fallaban, antes
    // nunca se llegaba a escuchar la renovación. Solo UNA vez (??=): esto se
    // llama en cada login y en cada apertura. Se cancela al cerrar sesión.
    try {
      _renovacionTokenFcm ??= FirebaseMessaging.instance.onTokenRefresh.listen(
        (nuevoToken) {
          if (haySesion) registroFcm.registrar(nuevoToken);
        },
        onError: (Object e) => debugPrint('Error en la renovación FCM: $e'),
      );
    } catch (e) {
      debugPrint('Error al escuchar la renovación FCM: $e');
    }

    // Al tocar un push se abre el pedido. Con la app abierta, el aviso entra
    // por AvisosEnVivo: la campanita se actualiza y el cascarón enseña la franja.
    unawaited(AperturasPush.escuchar());
    unawaited(AvisosEnVivo.escuchar());

    try {
      // 0. TG-183: desde Android 13 hay que pedir permiso para mostrar
      // notificaciones; sin esto el push llega pero el celular no lo enseña.
      // Si la persona ya contestó, no vuelve a preguntar.
      await FirebaseMessaging.instance.requestPermission();
    } catch (e) {
      // Sin permiso el token sigue sirviendo; no se corta el registro.
      debugPrint('Error al pedir permiso de notificaciones: $e');
    }

    try {
      // 1. Pedimos el token de este celular a Firebase y lo mandamos con
      // reintentos (sin red al abrir la app ya no se pierde el registro).
      if (haySesion) {
        await registroFcm.registrarDesde(FirebaseMessaging.instance.getToken);
      }
    } catch (e) {
      // Si Firebase falla, lo ignoramos para no tumbar la app.
      debugPrint('Error al registrar FCM: $e');
    }
  }
}