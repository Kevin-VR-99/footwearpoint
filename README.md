# FootwearPoint

FootwearPoint es una plataforma para distribuidoras de calzado por catálogo. Reúne en un solo lugar el catálogo, los pedidos a fábrica, los anticipos y saldos, los vales, las solicitudes de cambio y la recolección en sucursal (Click & Collect): un panel web para la distribuidora y su personal, y una app móvil para revendedores y clientes directos.

FootwearPoint no vende calzado. Es la plataforma tecnológica; la compraventa es entre la distribuidora y su revendedor o cliente.

> **Software propietario. Todos los derechos reservados.** Este repositorio no es de código abierto. Revisa [LICENSE](LICENSE) antes de usar, copiar o compartir el código.

## Equipo

- Ángel Gabriel Alcázar Pavón
- Francisco Rigoberto Morales Maldonado
- Kevin Arturo Velázquez Ríos
- Aurelio Fabián Paniagua Méndez
- Ailton Guadalupe Lievano Santiesteban

## Stack

**Backend y panel web**

- PHP 8.2 y Laravel 12
- Livewire 4 para las pantallas del panel
- Tailwind CSS 4, compilado con Vite 7
- Laravel Sanctum para la API que consume la app móvil
- spatie/laravel-permission para roles y permisos
- MySQL 8 en producción; MariaDB 10.4 (XAMPP) en los equipos del equipo

**App móvil** (`mobile/`)

- Flutter, con Dart SDK `^3.13.3`

**Servicios externos**

- Firebase Cloud Messaging para notificaciones push
- Mercado Pago para las suscripciones de las distribuidoras
- API de Anthropic para la importación asistida de catálogos de fábrica
- Almacenamiento compatible con S3 para catálogos, respaldos y exportaciones
- Sentry para el seguimiento de errores

**Generación de archivos**

- dompdf para los PDF y PhpSpreadsheet para los Excel

## Cómo correrlo en local

Necesitas PHP 8.2 con las extensiones `fileinfo`, `gd`, `intl`, `xmlwriter` y `zip`; Composer 2; Node 20 o superior (el equipo usa 22); y MySQL o MariaDB. En Windows lo normal es XAMPP: ahí hay que descomentar `extension=gd` y `extension=intl` en el `php.ini` y reiniciar Apache, porque vienen apagadas.

1. Clona el repositorio, entra en la carpeta e instala las dependencias de PHP:

   ```bash
   composer install
   ```

2. Crea tu `.env` a partir del ejemplo y genera la llave de la aplicación:

   ```bash
   cp .env.example .env
   php artisan key:generate
   ```

3. Abre el `.env` y completa los valores de tu entorno: la conexión a la base (`DB_*`), el correo (`MAIL_*`) y las credenciales de los servicios que vayas a probar. Los nombres de todas las variables están en `.env.example`. **Nunca subas claves reales al repositorio**: el `.env` está en el `.gitignore` y así debe quedarse.

4. Crea vacía la base de datos que pusiste en `DB_DATABASE` y aplica las migraciones. Si quieres datos de prueba, define antes `SEED_PASSWORD` en tu `.env` y agrega `--seed`:

   ```bash
   php artisan migrate --seed
   ```

5. Compila los assets:

   ```bash
   npm install
   npm run build
   ```

6. Levanta el entorno de desarrollo, que arranca el servidor, las colas, los logs y Vite a la vez:

   ```bash
   composer dev
   ```

Cuando ya tengas el `.env` configurado y la base creada, `composer setup` hace de un tirón los pasos 1, 2, 4 y 5.

### Pruebas

La suite usa la base `footwearpoint_testing`, fijada en `phpunit.xml`. Créala vacía antes de la primera corrida; las migraciones las aplican las pruebas.

```bash
php artisan test
```

Antes de escribir pruebas lee `docs/pruebas.md`: hay una diferencia entre la MariaDB local y el MySQL de producción en el orden de las claves de las columnas JSON que hace que una prueba pase aquí y falle allá.

### App móvil

```bash
cd mobile
flutter pub get
flutter run
```

## Documentación

Vive en `docs/`:

| Archivo | Contenido |
| --- | --- |
| `contrato-api.md` | Contrato de la API que consume la app móvil |
| `pruebas.md` | Reglas de pruebas y el detalle de MariaDB frente a MySQL |
| `railway-produccion.md` | Despliegue en Railway |
| `respaldos.md` | Respaldos de la base de datos |
| `correo.md` | Configuración del correo |
| `push-firebase.md` | Notificaciones push con Firebase |
| `apk.md` | Generación del APK de la app móvil |
| `vencer-recoleccion.md` | Avisos por plazo de recolección vencido |
| `aviso-vale-por-vencer.md` | Avisos de vales próximos a vencer |
| `encuesta-satisfaccion.md` y `encuesta/` | Encuesta de satisfacción y su análisis |

Los textos legales (términos y condiciones, aviso de privacidad) están en `resources/views/components/legales/`.
