<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;

/**
 * Enlaces para abrir la app móvil o descargarla.
 *
 * - urlDescarga(): APP_MOVIL_DESCARGA_URL (config/services.php) si está
 *   puesta; si no, la URL pública del APK en R2 (app-movil/footwearpoint.apk).
 * - urlAbrir(): intent de Android. Con la app instalada la abre en la
 *   pantalla indicada; sin ella, Chrome manda a urlDescarga().
 */
class AppMovil
{
    public static function urlDescarga(): string
    {
        $configurada = trim((string) config('services.app_movil.descarga_url'));

        if ($configurada !== '') {
            return $configurada;
        }

        try {
            return Storage::disk((string) config('footwearpoint.apk.disco'))
                ->url((string) config('footwearpoint.apk.ruta'));
        } catch (\Throwable $e) {
            report($e);

            return url('/');
        }
    }

    public static function urlAbrir(string $destino = 'perfil'): string
    {
        $host = (string) config('services.app_movil.host', 'footwearpoint.app');
        $paquete = (string) config('services.app_movil.paquete', 'com.footwearpoint.app');

        return 'intent://'.$host.'/abrir/'.ltrim($destino, '/')
            .'#Intent;scheme=https;package='.$paquete
            .';S.browser_fallback_url='.rawurlencode(self::urlDescarga())
            .';end';
    }
}
