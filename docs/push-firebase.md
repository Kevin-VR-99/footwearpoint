# Push (Firebase Cloud Messaging)

## Cómo decide el servidor

`AppServiceProvider` liga `EnviadorPush`:

- **Hay credenciales** → `EnviadorPushFirebase` (envío real con kreait/laravel-firebase).
- **No hay** → `EnviadorPushNulo`: no manda nada y escribe **una vez** en el log
  `Push desactivado: faltan las credenciales de Firebase…`. Los avisos siguen
  quedando en la bandeja de la app (`notificaciones`).

`App\Support\CredencialesFirebase::configuradas()` revisa, en orden:

1. `FIREBASE_CREDENTIALS` (o `GOOGLE_APPLICATION_CREDENTIALS`): ruta a un JSON que
   exista **o** el JSON de la cuenta de servicio en una sola línea.
2. `FIREBASE_CREDENTIALS_BASE64`: el mismo JSON en base64. Se decodifica en
   memoria; no se escribe a disco ni al log.

## Variables en Railway (servicio web y worker de colas)

| Variable | Obligatoria | Valor |
|---|---|---|
| `FIREBASE_CREDENTIALS_BASE64` | Sí (o la siguiente) | `base64 -w0 cuenta-servicio.json` |
| `FIREBASE_CREDENTIALS` | Alternativa | JSON en una línea, o ruta a un archivo montado |
| `FIREBASE_PROJECT` | No | Nombre del proyecto en `config/firebase.php` (por omisión `app`) |

La cuenta de servicio se genera en Firebase Console → Configuración del proyecto →
Cuentas de servicio → *Generar nueva clave privada*. **No se sube al repo.**
Después de cambiar variables: redeploy (o `php artisan config:clear` si hay caché de config).

Comprobar: con un celular registrado, cambiar un pedido a «listo para entrega».
Si en el log aparece «Push desactivado», faltan las variables.

## Flujo del token FCM (app)

1. `main.dart`: `Firebase.initializeApp()` (usa `google-services.json` /
   `GoogleService-Info.plist` del proyecto móvil).
2. Al iniciar sesión, `AuthProvider` pide permiso, obtiene `FirebaseMessaging.instance.getToken()`
   y escucha `onTokenRefresh`.
3. `services/registro_fcm.dart` hace `POST /api/dispositivos-fcm` con `{token, plataforma}`
   (con reintentos si no hay red). El servidor guarda `dispositivos_fcm` ligado al
   usuario y a su token de Sanctum.
4. Al cerrar sesión o al **dar de baja** el acceso, el token Sanctum se borra y con él
   el dispositivo (ON DELETE CASCADE / `RevocarAccesoUsuarioAction`).
5. Los tokens que Firebase reporta inválidos se borran al enviar.
