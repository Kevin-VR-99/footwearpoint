import 'package:flutter_test/flutter_test.dart';
import 'package:footwearpoint/providers/auth_provider.dart';
import 'package:footwearpoint/services/catalogo_service.dart';
import 'package:footwearpoint/services/notificacion_service.dart';

/// El reloj de las pruebas no avanza las esperas del registro FCM.
/// Sin esto, un login deja temporizadores de hasta 32 s y la prueba se cuelga.
Future<void> testExecutable(Future<void> Function() testMain) async {
  AuthProvider.registrarPush = false;
  setUp(() {
    CatalogoService.olvidarCache();
    NotificacionService.olvidarCache();
  });
  await testMain();
}
