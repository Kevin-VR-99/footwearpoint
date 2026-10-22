import 'package:footwearpoint/providers/auth_provider.dart';

/// El reloj de las pruebas no avanza las esperas del registro FCM.
/// Sin esto, un login deja temporizadores de hasta 32 s y la prueba se cuelga.
Future<void> testExecutable(Future<void> Function() testMain) async {
  AuthProvider.registrarPush = false;
  await testMain();
}
