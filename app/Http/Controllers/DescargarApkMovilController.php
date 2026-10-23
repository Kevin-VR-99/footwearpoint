<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * El admin general descarga la app de Android.
 *
 * Siempre es el mismo archivo: app-movil/footwearpoint.apk en el disco de
 * productos. Reemplazar ese objeto deja el botón en la versión nueva, sin
 * cambiar la ruta ni el nombre que recibe quien lo descarga.
 */
class DescargarApkMovilController extends Controller
{
    public function __invoke(): StreamedResponse|Response
    {
        $disco = Storage::disk((string) config('footwearpoint.apk.disco'));
        $ruta = (string) config('footwearpoint.apk.ruta');

        if (! $disco->exists($ruta)) {
            return response()->view('errors.apk-no-publicada', [], 404);
        }

        return $disco->download($ruta, 'footwearpoint.apk', [
            'Content-Type' => 'application/vnd.android.package-archive',
        ]);
    }
}
