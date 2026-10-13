import java.util.Properties

plugins {
    id("com.android.application")
    // The Flutter Gradle Plugin must be applied after the Android and Kotlin Gradle plugins.
    id("dev.flutter.flutter-gradle-plugin")
    // Firebase: procesa google-services.json de esta misma carpeta.
    id("com.google.gms.google-services")
}

// Llave de firma para publicar (TG-241).
//
// Android solo deja actualizar una app si la nueva version viene firmada con
// la MISMA llave que la anterior. Si se pierde, no hay forma de volver a
// firmar: habria que publicar otra app y pedirle a cada persona que la
// instale de cero. Por eso el archivo key.properties y el .jks viven fuera
// del repositorio (ya estan en android/.gitignore) y se guardan aparte.
//
// Quien no tenga la llave (el resto del equipo) puede seguir compilando: sin
// key.properties se firma con la de depuracion, que sirve para probar pero NO
// para publicar ni para actualizar lo que ya esta publicado.
val archivoLlave = rootProject.file("key.properties")
val llave = Properties().apply {
    if (archivoLlave.exists()) {
        archivoLlave.inputStream().use { load(it) }
    }
}
val hayLlaveDePublicacion = archivoLlave.exists()

android {
    namespace = "com.footwearpoint.footwearpoint"
    compileSdk = flutter.compileSdkVersion
    ndkVersion = flutter.ndkVersion

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }

    defaultConfig {
        // Identificador publico de la app en Android. Es el que hay que
        // registrar en Firebase: el google-services.json solo funciona si su
        // package_name coincide EXACTAMENTE con este valor.
        //
        // El "namespace" de arriba se queda como lo genero Flutter porque es
        // interno (define la carpeta del codigo Kotlin) y no tiene que
        // coincidir con este.
        applicationId = "com.footwearpoint.app"
        // You can update the following values to match your application needs.
        // For more information, see: https://flutter.dev/to/review-gradle-config.
        minSdk = flutter.minSdkVersion
        targetSdk = flutter.targetSdkVersion
        // Uses the version code from pubspec.yaml. When using split APKs, 1000 * ABI_VERSION
        // is added automatically by Flutter. (https://developer.android.com/studio/build/configure-apk-splits#configure-APK-versions)
        // You can force using the value of versionCode by specifying the `-P force-version-code-ignoring-abi=true`
        // flag during build.
        versionCode = flutter.versionCode
        versionName = flutter.versionName
    }

    signingConfigs {
        if (hayLlaveDePublicacion) {
            create("publicacion") {
                keyAlias = llave["keyAlias"] as String
                keyPassword = llave["keyPassword"] as String
                storeFile = file(llave["storeFile"] as String)
                storePassword = llave["storePassword"] as String
            }
        }
    }

    buildTypes {
        release {
            signingConfig = if (hayLlaveDePublicacion) {
                signingConfigs.getByName("publicacion")
            } else {
                // Sirve para probar en el celular, no para publicar.
                logger.lifecycle(
                    "AVISO: no hay android/key.properties, asi que este APK se firma con la llave " +
                        "de depuracion. No se puede subir a la tienda ni actualiza al que ya esta instalado.",
                )
                signingConfigs.getByName("debug")
            }
        }
    }
}

kotlin {
    compilerOptions {
        jvmTarget = org.jetbrains.kotlin.gradle.dsl.JvmTarget.JVM_17
    }
}

flutter {
    source = "../.."
}
