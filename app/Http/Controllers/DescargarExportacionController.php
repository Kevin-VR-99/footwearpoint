<?php

namespace App\Http\Controllers;

use App\Models\Exportacion;
use App\Support\MensajeError;
use App\Support\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Descarga de una exportación en cola. Pide las tres cosas: enlace firmado
 * vigente (middleware 'signed'), sesión de quien la pidió y la misma
 * distribuidora. Cualquier otro caso responde 404/403 sin decir si existe.
 */
class DescargarExportacionController extends Controller
{
    public function __invoke(Request $request, int $exportacion): Response
    {
        $tenant = Tenant::id();
        abort_if($tenant === null, 403, MensajeError::SIN_PERMISO);

        // El scope de tenant ya filtra por distribuidora; se repite a propósito.
        $e = Exportacion::query()
            ->whereKey($exportacion)
            ->where('distribuidora_id', $tenant)
            ->where('usuario_id', $request->user()->id)
            ->first();

        abort_if($e === null, 404);
        abort_unless($e->disponible(), 410, 'El archivo ya no está disponible. Vuelve a generarlo desde la pantalla.');
        abort_unless(Storage::disk($e->disco)->exists($e->ruta), 410, 'El archivo ya no está disponible. Vuelve a generarlo desde la pantalla.');

        $tipo = $e->formato === 'pdf' ? 'application/pdf' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

        return Storage::disk($e->disco)->download($e->ruta, $e->nombre_archivo, [
            'Content-Type' => $tipo,
            'Cache-Control' => 'no-store, private',
        ]);
    }
}
