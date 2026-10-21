import 'package:flutter_test/flutter_test.dart';
import 'package:footwearpoint/config.dart';

void main() {
  test('sin --dart-define la app apunta a la API de produccion por https', () {
    // Las pruebas corren sin --dart-define, asi que esto es el valor por omision.
    expect(Config.urlBaseApi, 'https://footwearpoint.app/api');
    expect(Config.urlBaseApi.startsWith('https://'), isTrue);
  });
}
