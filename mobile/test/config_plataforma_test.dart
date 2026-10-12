import 'package:flutter/foundation.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:footwearpoint/config.dart';

/// TG-183: la app ya no da por hecho que corre en Android.
void main() {
  tearDown(() => debugDefaultTargetPlatformOverride = null);

  test('en iOS registra el dispositivo como ios y usa 127.0.0.1 por omisión', () {
    debugDefaultTargetPlatformOverride = TargetPlatform.iOS;
    expect(Config.plataformaFcm, 'ios');
    expect(Config.urlBaseApi, 'http://127.0.0.1:8000/api');
  });

  test('en Android sigue igual: android y 10.0.2.2', () {
    debugDefaultTargetPlatformOverride = TargetPlatform.android;
    expect(Config.plataformaFcm, 'android');
    expect(Config.urlBaseApi, 'http://10.0.2.2:8000/api');
  });
}
